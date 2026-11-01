<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\StockReservationStatus;
use App\Events\StockChanged;
use App\Kafka\Saga\StockSagaMessage;
use App\Services\Products\ProductCache;
use Illuminate\Support\Facades\Log;

final class CommitStockService
{
    public function __construct(
        private StockItemNormalizer $normalizer,
        private StockReservationLedger $ledger,
        private ProductCache $cache,
    ) {}

    /**
     * Finalize a paid order: decrease physical quantity and mark the reservation committed.
     *
     * @return bool true when items were valid and processed
     */
    public function handle(StockSagaMessage $message): bool
    {
        $orderId = $message->orderId;
        $normalized = $this->normalizer->normalize($message->items);

        if ($normalized === []) {
            Log::warning('stock.commit skipped: invalid items', ['order_id' => $orderId]);

            return false;
        }

        $this->ledger->withinTransaction(function () use ($orderId, $normalized): void {
            $stockChangedEvents = [];

            foreach ($normalized as $item) {
                $stock = $this->ledger->settleHeld($orderId, $item, StockReservationStatus::Committed, true);
                $stockChangedEvents[] = $this->ledger->stockChangedPayload($stock);
            }

            foreach ($stockChangedEvents as $changedEvent) {
                event(new StockChanged($changedEvent));
            }

            Log::info("Списание после оплаты: заказ #{$orderId}, quantity и reserved обновлены.");
        });

        $this->cache->clear();

        return true;
    }
}
