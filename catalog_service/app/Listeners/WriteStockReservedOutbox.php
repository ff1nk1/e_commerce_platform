<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockReserved;
use App\Services\CatalogOutbox;
use Shop\Messaging\Topics;

final class WriteStockReservedOutbox
{
    public function __construct(
        private CatalogOutbox $outbox,
    ) {}

    public function handle(StockReserved $event): void
    {
        $key = isset($event->payload['order_id']) ? (string) $event->payload['order_id'] : 'stock.reserved';
        $this->outbox->publish(Topics::STOCK_EVENTS, 'stock.reserved', $event->payload, $key);
    }
}
