<?php

namespace App\Services;

use App\Events\StockChanged;
use App\Events\StockReservationFailed;
use App\Events\StockReserved;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockReservationService
{
    /**
     * Reserve stock for an order by increasing `reserved` (physical `quantity` unchanged).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{outcome: 'reserved'|'reservation_failed', order_id: int|string}
     */
    public function reserve(int|string $orderId, array $items, ?string $sagaId = null): array
    {
        $normalized = $this->normalizeItems($items);
        if ($normalized === null) {
            $this->emitReservationFailed($orderId, $sagaId, 'Invalid stock items');

            return ['outcome' => 'reservation_failed', 'order_id' => $orderId];
        }

        return DB::transaction(function () use ($orderId, $normalized, $sagaId) {
            /** @var list<array{0: Stock, 1: int}> $toReserve */
            $toReserve = [];

            foreach ($normalized as $item) {
                $stock = Stock::where('product_id', $item['product_id'])
                    ->where('store_id', $item['store_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $stock || $stock->available < $item['quantity']) {
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
    public function release(int|string $orderId, array $items, ?string $sagaId = null): bool
    {
        $normalized = $this->normalizeItems($items);
        if ($normalized === null) {
            Log::warning('stock.release skipped: invalid items', ['order_id' => $orderId]);

            return false;
        }

        DB::transaction(function () use ($orderId, $normalized) {
            $stockChangedEvents = [];

            foreach ($normalized as $item) {
                $stock = Stock::where('product_id', $item['product_id'])
                    ->where('store_id', $item['store_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    continue;
                }

                $stock->reserved = max(0, $stock->reserved - $item['quantity']);
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
     * @param  list<array<string, mixed>>  $items
     * @return list<array{product_id: int, store_id: int, quantity: int}>|null
     */
    private function normalizeItems(array $items): ?array
    {
        if ($items === []) {
            return null;
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                return null;
            }

            $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $storeId = filter_var($item['store_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);

            if ($productId === false || $productId < 1
                || $storeId === false || $storeId < 1
                || $quantity === false || $quantity < 1) {
                return null;
            }

            $normalized[] = [
                'product_id' => $productId,
                'store_id' => $storeId,
                'quantity' => $quantity,
            ];
        }

        return $normalized;
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
