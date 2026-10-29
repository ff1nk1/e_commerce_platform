<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\StockChanged;
use App\Events\StockReservationFailed;
use App\Events\StockReserved;
use App\Exceptions\DuplicateStockReservationException;
use App\Exceptions\InsufficientStockReservationException;
use App\Exceptions\MissingStockReservationException;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Services\Stock\StockItemNormalizer;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StockReservationService
{
    public function __construct(
        private StockItemNormalizer $normalizer,
    ) {}

    /**
     * Reserve stock for an order by recording a held ledger row and increasing `reserved`.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{outcome: 'reserved'|'reservation_failed', order_id: int|string}
     */
    public function reserve(int|string $orderId, array $items, ?string $sagaId = null): array
    {
        $normalized = $this->normalizer->normalize($items);
        if ($normalized === null) {
            $this->emitReservationFailed($orderId, $sagaId, 'Invalid stock items');

            return ['outcome' => 'reservation_failed', 'order_id' => $orderId];
        }

        return $this->withinTransaction(function () use ($orderId, $normalized, $sagaId): array {
            $orderKey = $this->orderKey($orderId);

            /** @var list<array{0: Stock, 1: int}> $toReserve */
            $toReserve = [];

            foreach ($normalized as $item) {
                $stock = Stock::query()->where('product_id', $item['product_id'])
                    ->where('store_id', $item['store_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $stock instanceof Stock || $stock->available < $item['quantity']) {
                    $this->emitReservationFailed($orderId, $sagaId, 'Not enough stock');

                    return ['outcome' => 'reservation_failed', 'order_id' => $orderId];
                }

                $toReserve[] = [$stock, $item['quantity']];
            }

            $stockChangedEvents = [];

            foreach ($toReserve as [$stock, $quantity]) {
                $existing = StockReservation::query()
                    ->where('order_id', $orderKey)
                    ->where('product_id', $stock->product_id)
                    ->where('store_id', $stock->store_id)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof StockReservation) {
                    throw new DuplicateStockReservationException(
                        "Reservation already exists for order {$orderKey}, product {$stock->product_id}, store {$stock->store_id}."
                    );
                }

                StockReservation::query()->create([
                    'order_id' => $orderKey,
                    'product_id' => $stock->product_id,
                    'store_id' => $stock->store_id,
                    'quantity' => $quantity,
                    'status' => StockReservation::STATUS_HELD,
                ]);

                $stock->reserved += $quantity;
                $stock->save();
                $stockChangedEvents[] = $this->stockChangedPayload($stock);
            }

            $reservedPayload = [
                'order_id' => $orderId,
                'status' => 'success',
            ];
            if ($sagaId !== null) {
                $reservedPayload['saga_id'] = $sagaId;
            }

            event(new StockReserved($reservedPayload));

            foreach ($stockChangedEvents as $changedEvent) {
                event(new StockChanged($changedEvent));
            }

            Log::info("Товар для заказа #{$orderId} зарезервирован. Событие отправлено в Outbox.");

            return ['outcome' => 'reserved', 'order_id' => $orderId];
        });
    }

    /**
     * Release a held reservation for this order.
     *
     * @param  list<array<string, mixed>>  $items
     * @return bool true when items were valid and processed
     */
    public function release(int|string $orderId, array $items): bool
    {
        $normalized = $this->normalizer->normalize($items);
        if ($normalized === null) {
            Log::warning('stock.release skipped: invalid items', ['order_id' => $orderId]);

            return false;
        }

        $this->withinTransaction(function () use ($orderId, $normalized): void {
            $stockChangedEvents = [];

            foreach ($normalized as $item) {
                $stock = $this->settleHeld($orderId, $item, StockReservation::STATUS_RELEASED, false);
                $stockChangedEvents[] = $this->stockChangedPayload($stock);
            }

            foreach ($stockChangedEvents as $changedEvent) {
                event(new StockChanged($changedEvent));
            }

            Log::info("Резерв снят: товар для заказа #{$orderId} возвращен на склад.");
        });

        return true;
    }

    /**
     * Finalize a paid order: decrease physical quantity and mark the reservation committed.
     *
     * @param  list<array<string, mixed>>  $items
     * @return bool true when items were valid and processed
     */
    public function commit(int|string $orderId, array $items): bool
    {
        $normalized = $this->normalizer->normalize($items);
        if ($normalized === null) {
            Log::warning('stock.commit skipped: invalid items', ['order_id' => $orderId]);

            return false;
        }

        $this->withinTransaction(function () use ($orderId, $normalized): void {
            $stockChangedEvents = [];

            foreach ($normalized as $item) {
                $stock = $this->settleHeld($orderId, $item, StockReservation::STATUS_COMMITTED, true);
                $stockChangedEvents[] = $this->stockChangedPayload($stock);
            }

            foreach ($stockChangedEvents as $changedEvent) {
                event(new StockChanged($changedEvent));
            }

            Log::info("Списание после оплаты: заказ #{$orderId}, quantity и reserved обновлены.");
        });

        return true;
    }

    /**
     * @param  array{product_id: int, store_id: int, quantity: int}  $item
     */
    private function settleHeld(int|string $orderId, array $item, string $nextStatus, bool $decrementQuantity): Stock
    {
        $orderKey = $this->orderKey($orderId);
        $productId = $item['product_id'];
        $storeId = $item['store_id'];
        $requested = $item['quantity'];

        $stock = Stock::query()
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first();

        $reservation = StockReservation::query()
            ->where('order_id', $orderKey)
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->where('status', StockReservation::STATUS_HELD)
            ->lockForUpdate()
            ->first();

        throw_if(
            ! $stock instanceof Stock || ! $reservation instanceof StockReservation,
            MissingStockReservationException::class,
            "No held reservation for order {$orderKey}, product {$productId}, store {$storeId}."
        );

        if ($reservation->quantity !== $requested || $stock->reserved < $requested) {
            throw new InsufficientStockReservationException(
                "Held {$reservation->quantity} (counter {$stock->reserved}) for order {$orderKey}, requested {$requested}."
            );
        }

        if ($decrementQuantity && $stock->quantity < $requested) {
            throw new InsufficientStockReservationException(
                "Physical quantity {$stock->quantity} is below commit of {$requested} for order {$orderKey}."
            );
        }

        $reservation->status = $nextStatus;
        $reservation->save();

        if ($decrementQuantity) {
            $stock->quantity -= $requested;
        }
        $stock->reserved -= $requested;
        $stock->save();

        return $stock;
    }

    private function orderKey(int|string $orderId): int
    {
        if (is_int($orderId) && $orderId >= 1) {
            return $orderId;
        }

        if (is_string($orderId) && ctype_digit($orderId) && (int) $orderId >= 1) {
            return (int) $orderId;
        }

        throw new MissingStockReservationException('Order id is not a positive integer.');
    }

    /**
     * Join a PDO transaction Ecotone already opened, or start a Laravel one.
     *
     * Inbox workers begin the transaction through Doctrine, so Laravel's
     * counter stays at 0. A nested begin then throws "already an active transaction".
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withinTransaction(Closure $callback): mixed
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();

        if ($connection->transactionLevel() === 0 && $pdo->inTransaction()) {
            return $callback();
        }

        return $connection->transaction(function (Connection $connection) use ($callback) {
            return $callback();
        });
    }

    /**
     * @return array{product_id: int, store_id: int, quantity: int}
     */
    private function stockChangedPayload(Stock $stock): array
    {
        return [
            'product_id' => $stock->product_id,
            'store_id' => $stock->store_id,
            // Downstream (e.g. recommendation) treats this as sellable/available qty.
            'quantity' => $stock->available,
        ];
    }

    private function emitReservationFailed(int|string $orderId, ?string $sagaId, string $reason): void
    {
        $failedPayload = [
            'order_id' => $orderId,
            'reason' => $reason,
        ];
        if ($sagaId !== null) {
            $failedPayload['saga_id'] = $sagaId;
        }

        event(new StockReservationFailed($failedPayload));
        Log::warning("Резервация для заказа #{$orderId} не удалась ({$reason}). Событие reservation_failed отправлено в Outbox.");
    }
}
