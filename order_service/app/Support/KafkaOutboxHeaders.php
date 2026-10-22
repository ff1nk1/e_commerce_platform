<?php

namespace App\Support;

use App\Models\OutboxEvent;

final class KafkaOutboxHeaders
{
    /**
     * @return array<string, string>
     */
    public static function forEvent(OutboxEvent $event, string $source): array
    {
        $headers = [
            'event-type' => $event->event_type,
            'source' => $source,
        ];

        $sagaId = KafkaPayload::sagaId($event->payload);
        if ($sagaId !== null) {
            $headers['saga-id'] = $sagaId;
        }

        return $headers;
    }
}
