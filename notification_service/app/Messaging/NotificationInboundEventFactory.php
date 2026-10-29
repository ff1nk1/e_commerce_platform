<?php

declare(strict_types=1);

namespace App\Messaging;

use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class NotificationInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return match (true) {
            str_starts_with($envelope->eventType, 'user.') => new UserNotification($envelope),
            str_starts_with($envelope->eventType, 'order.') => new OrderNotification($envelope),
            default => null,
        };
    }
}
