<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockReservationFailed;
use App\Services\CatalogOutbox;
use Shop\Messaging\Topics;

final class WriteStockReservationFailedOutbox
{
    public function __construct(
        private CatalogOutbox $outbox,
    ) {}

    public function handle(StockReservationFailed $event): void
    {
        $key = isset($event->payload['order_id']) ? (string) $event->payload['order_id'] : 'stock.reservation_failed';
        $this->outbox->publish(Topics::STOCK_EVENTS, 'stock.reservation_failed', $event->payload, $key);
    }
}
