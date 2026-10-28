<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockChanged;
use App\Services\OutboxWriter;

final class WriteStockChangedOutbox
{
    public function __construct(
        private OutboxWriter $outbox,
    ) {}

    public function handle(StockChanged $event): void
    {
        $this->outbox->write('catalog.events', 'stock.changed', $event->payload);
    }
}
