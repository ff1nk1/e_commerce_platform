<?php

declare(strict_types=1);

namespace App\Messaging;

use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class OrderInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return match ($envelope->eventType) {
            'product.created', 'product.updated' => new ProductSnapshotReceived($envelope),
            'stock.reserved' => new StockReserved($envelope),
            'stock.reservation_failed' => new StockReservationFailed($envelope),
            default => null,
        };
    }
}
