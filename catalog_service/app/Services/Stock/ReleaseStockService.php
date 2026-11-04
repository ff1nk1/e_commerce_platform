<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\StockReservationStatus;
use App\Events\StockChanged;
use App\Saga\StockSagaMessage;
use App\Services\Products\ProductCache;
use Illuminate\Support\Facades\Log;

final class ReleaseStockService
{
    public function __construct(
        private StockItemNormalizer $normalizer,
        private StockReservationLedger $ledger,
        private ProductCache $cache,
    ) {}

    /**
     * Release a held reservation for this order.
     *
     * @return bool true when items were valid and processed
     */
    public function handle(StockSagaMessage $message): bool
    {
        $orderId = $message->orderId;
        $normalized = $this->normalizer->normalizeSettlement($message->items);

        if ($normalized === []) {
            Log::warning('stock.release skipped: invalid items', ['order_id' => $orderId]);

            return false;
        }

        $this->ledger->withinTransaction(function () use ($orderId, $normalized, $message): void {
            $stockChangedEvents = [];

            foreach ($normalized as $item) {
                $stock = $this->ledger->settleHeld($orderId, $item, StockReservationStatus::Released, false, $message->sagaId);
                $stockChangedEvents[] = $this->ledger->stockChangedPayload($stock);
            }

            foreach ($stockChangedEvents as $changedEvent) {
                event(new StockChanged($changedEvent));
            }

            Log::info("Резерв снят: товар для заказа #{$orderId} возвращен на склад.");
        });

        $this->cache->clear();

        return true;
    }
}
