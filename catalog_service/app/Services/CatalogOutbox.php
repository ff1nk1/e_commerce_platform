<?php

declare(strict_types=1);

namespace App\Services;

use Shop\Messaging\OutboundMessage;
use Shop\Messaging\OutboxPublisher;

final class CatalogOutbox
{
    public function __construct(
        private OutboxPublisher $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $topic, string $eventType, array $payload, string $key): void
    {
        $sagaId = isset($payload['saga_id']) && is_string($payload['saga_id']) && $payload['saga_id'] !== ''
            ? $payload['saga_id']
            : null;
        $payload['event_type'] = $eventType;
        $payload['event'] = $eventType;

        $this->outbox->publish(new OutboundMessage(
            topic: $topic,
            key: $key,
            eventType: $eventType,
            payload: $payload,
            source: 'catalog-service',
            sagaId: $sagaId,
        ));
    }
}
