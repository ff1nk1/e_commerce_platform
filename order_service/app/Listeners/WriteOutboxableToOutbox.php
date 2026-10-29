<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\Outboxable;
use Shop\Messaging\OutboundMessage;
use Shop\Messaging\OutboxPublisher;

final class WriteOutboxableToOutbox
{
    public function __construct(
        private OutboxPublisher $outbox,
    ) {}

    public function handle(Outboxable $event): void
    {
        $payload = $event->outboxPayload();
        $payload['event_type'] = $event->outboxEventType();
        $payload['event'] = $event->outboxEventType();
        $sagaId = isset($payload['saga_id']) && is_string($payload['saga_id']) && $payload['saga_id'] !== ''
            ? $payload['saga_id']
            : null;
        $keySource = $payload['order_id'] ?? $sagaId ?? $event->outboxEventType();

        $this->outbox->publish(new OutboundMessage(
            topic: $event->outboxTopic(),
            key: is_scalar($keySource) ? (string) $keySource : $event->outboxEventType(),
            eventType: $event->outboxEventType(),
            payload: $payload,
            source: 'order-service',
            sagaId: $sagaId,
        ));
    }
}
