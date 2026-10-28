<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockReservationFailed;
use App\Services\OutboxWriter;

final class WriteStockReservationFailedOutbox
{
    public function __construct(
        private OutboxWriter $outbox,
    ) {}

    public function handle(StockReservationFailed $event): void
    {
        $this->outbox->write('stock.events', 'stock.reservation_failed', $event->payload);
    }
}
