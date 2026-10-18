<?php

namespace App\Kafka\Concerns;

use Junges\Kafka\Contracts\ConsumerMessage;

trait ResolvesEventType
{
    /**
     * @return array{0: mixed, 1: mixed}
     */
    protected function resolveEventTypeAndBody(ConsumerMessage $message): array
    {
        $headers = $message->getHeaders();
        $body = $message->getBody();
        $eventType = $headers['event-type'] ?? ($body['event'] ?? null);

        return [$eventType, $body];
    }
}
