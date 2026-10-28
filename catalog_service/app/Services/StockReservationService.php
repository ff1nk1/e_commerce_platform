<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\StockChanged;
use App\Events\StockReservationFailed;
use App\Events\StockReserved;
use App\Models\Stock;
use App\Services\Stock\StockItemNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StockReservationService
{
    public function __construct(
        private StockItemNormalizer $normalizer,
    ) {}

    /**
     * Reserve stock for an order by increasing `reserved` (physical `quantity` unchanged).
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

        return DB::transaction(function () use ($orderId, $normalized, $sagaId): array {
            /** @var list<array{0: Stock, 1: int}> $toReserve */
            $toReserve = [];

            foreach ($normalized as $item) {
                $stock = Stock::query()->where('product_id', $item['product_id'])
                    ->where('store_id', $item['store_id'])
                    ->lockForUpdate()
                    ->first();

                $stockMissing = ! $stock;
                $hasEnoughStock = $stock && $stock->available >= $item['quantity'];
                if ($stockMissing || ! $hasEnoughStock) {
                    $this->emitReservationFailed($orderId, $sagaId, 'Not enough stock');

                    return ['outcome' => 'reservation_failed', 'order_id' => $orderId];
                }

                $toReserve[] = [$stock, $item['quantity']];
            }

            $stockChangedEvents = [];

            foreach ($toReserve as [$stock, $quantity]) {
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
     * Release previously reserved stock by decreasing `reserved`.
     *
     * @param  list<array<string, mixed>>  $items
     * @return bool true when items were valid and processed (even if some rows missing)
     */
    public function release(int|string $orderId, array $items): bool
    {
        $normalized = $this->normalizer->normalize($items);
        if ($normalized === null) {
            Log::warning('stock.release skipped: invalid items', ['order_id' => $orderId]);

            return false;
        }

        DB::transaction(function () use ($orderId, $normalized): void {
            $stockChangedEvents = [];

            foreach ($normalized as $item) {
                $stock = Stock::query()->where('product_id', $item['product_id'])
                    ->where('store_id', $item['store_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    continue;
                }

                $remainingReserved = max(0, $stock->reserved - $item['quantity']);
                $stock->reserved = $remainingReserved;
                $stock->save();

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
     * Finalize a paid order: decrease physical quantity and clear the reservation hold.
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

        DB::transaction(function () use ($orderId, $normalized): void {
            $stockChangedEvents = [];

            foreach ($normalized as $item) {
                $stock = Stock::query()->where('product_id', $item['product_id'])
                    ->where('store_id', $item['store_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    continue;
                }

                $qty = $item['quantity'];
                $debit = min($stock->reserved, $qty);
                if ($debit < $qty) {
                    Log::warning('stock.commit under-reserved; debiting only held qty', [
                        'order_id' => $orderId,
                        'product_id' => $item['product_id'],
                        'store_id' => $item['store_id'],
                        'reserved' => $stock->reserved,
                        'requested' => $qty,
                        'debit' => $debit,
                    ]);
                }

                if ($debit === 0) {
                    continue;
                }

                $stock->quantity -= $debit;
                $stock->reserved -= $debit;
                $stock->save();

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
