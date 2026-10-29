<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockChanged;
use App\Services\CatalogOutbox;
use Shop\Messaging\Topics;

final class WriteStockChangedOutbox
{
    public function __construct(
        private CatalogOutbox $outbox,
    ) {}

    public function handle(StockChanged $event): void
    {
        $productId = (string) $event->payload['product_id'];
        $this->outbox->publish(Topics::CATALOG_EVENTS, 'stock.changed', $event->payload, $productId);
    }
}
