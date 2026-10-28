<?php

declare(strict_types=1);

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
        $headerEventType = $headers['event-type'] ?? null;
        $bodyEventType = null;
        $eventType = $headerEventType;
        if ($eventType === null) {
            $bodyEventType = $body['event'] ?? null;
            $eventType = $bodyEventType;
        }

        return [$eventType, $body];
    }
}
