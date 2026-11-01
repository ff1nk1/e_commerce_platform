<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Enums\OrderEventType;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class OrderInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return match (OrderEventType::tryFrom($envelope->eventType)) {
            OrderEventType::ProductCreated, OrderEventType::ProductUpdated => new ProductSnapshotReceived($envelope),
            OrderEventType::StockReserved => new StockReserved($envelope),
            OrderEventType::StockReservationFailed => new StockReservationFailed($envelope),
            default => null,
        };
    }
}
