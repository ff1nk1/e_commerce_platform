<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\DTO\Stock\StockReservationResult;
use App\Enums\ReservationOutcome;
use App\Enums\StockReservationStatus;
use App\Events\StockChanged;
use App\Events\StockReservationFailed;
use App\Events\StockReserved;
use App\Exceptions\DuplicateStockReservationException;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Saga\StockSagaMessage;
use App\Services\Products\ProductCache;
use Illuminate\Support\Facades\Log;

final class ReserveStockService
{
    public function __construct(
        private StockItemNormalizer $normalizer,
        private StockReservationLedger $ledger,
        private ProductCache $cache,
    ) {}

    /**
     * Reserve stock for an order by recording a held ledger row and increasing `reserved`.
     */
    public function handle(StockSagaMessage $message): StockReservationResult
    {
        $orderId = $message->orderId;
        $sagaId = $message->sagaId;
        $normalized = $this->normalizer->normalize($message->items);

        if ($normalized === []) {
            $this->emitReservationFailed($orderId, $sagaId, 'Invalid stock items');

            return new StockReservationResult(ReservationOutcome::ReservationFailed, $orderId);
        }

        $result = $this->ledger->withinTransaction(function () use ($orderId, $normalized, $sagaId): StockReservationResult {
            $orderKey = $this->ledger->orderKey($orderId);

            /** @var list<array{0: Stock, 1: int}> $toReserve */
            $toReserve = [];

            foreach ($normalized as $item) {
                $stock = Stock::query()->where('product_id', $item->productId)
                    ->where('store_id', $item->storeId)
                    ->lockForUpdate()
                    ->first();

                if (! $stock instanceof Stock || $stock->available < $item->quantity) {
                    $this->emitReservationFailed($orderId, $sagaId, 'Not enough stock');

                    return new StockReservationResult(ReservationOutcome::ReservationFailed, $orderId);
                }

                $toReserve[] = [$stock, $item->quantity];
            }

            $stockChangedEvents = [];

            foreach ($toReserve as [$stock, $quantity]) {
                $existing = StockReservation::query()
                    ->where('order_id', $orderKey)
                    ->where('product_id', $stock->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof StockReservation) {
                    throw new DuplicateStockReservationException(
                        "Reservation already exists for order {$orderKey}, product {$stock->product_id}."
                    );
                }

                StockReservation::query()->create([
                    'order_id' => $orderKey,
                    'saga_id' => $sagaId !== '' ? $sagaId : null,
                    'product_id' => $stock->product_id,
                    'store_id' => $stock->store_id,
                    'quantity' => $quantity,
                    'status' => StockReservationStatus::Held,
                ]);

                $stock->reserved += $quantity;
                $stock->save();
                $stockChangedEvents[] = $this->ledger->stockChangedPayload($stock);
            }

            $reservedPayload = [
                'order_id' => $orderId,
                'status' => 'success',
            ];
            if ($sagaId !== '') {
                $reservedPayload['saga_id'] = $sagaId;
            }

            event(new StockReserved($reservedPayload));

            foreach ($stockChangedEvents as $changedEvent) {
                event(new StockChanged($changedEvent));
            }

            Log::info("Товар для заказа #{$orderId} зарезервирован. Событие отправлено в Outbox.");

            return new StockReservationResult(ReservationOutcome::Reserved, $orderId);
        });

        if ($result->outcome === ReservationOutcome::Reserved) {
            $this->cache->clear();
        }

        return $result;
    }

    private function emitReservationFailed(int|string $orderId, string $sagaId, string $reason): void
    {
        $failedPayload = [
            'order_id' => $orderId,
            'reason' => $reason,
        ];
        if ($sagaId !== '') {
            $failedPayload['saga_id'] = $sagaId;
        }

        event(new StockReservationFailed($failedPayload));
        Log::warning("Резервация для заказа #{$orderId} не удалась ({$reason}). Событие reservation_failed отправлено в Outbox.");
    }
}
