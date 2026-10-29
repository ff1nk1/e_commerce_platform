<?php

declare(strict_types=1);

namespace App\Kafka\Concerns;

use Shop\Messaging\EnvelopeMessage;

trait ResolvesEventType
{
    /**
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    protected function resolveEventTypeAndBody(EnvelopeMessage $message): array
    {
        $headers = $message->getHeaders();
        $body = $message->getBody();
        $eventType = $headers['event-type'] ?? '';
        if ($eventType === '') {
            $eventType = $body['event_type'] ?? $body['event'] ?? null;
        }

        return [$eventType, $body];
    }
}
