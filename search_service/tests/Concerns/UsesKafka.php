<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Junges\Kafka\Facades\Kafka;
use Shop\Messaging\InboundEnvelope;

trait UsesKafka
{
    protected function fakeKafka(): void
    {
        Kafka::fake();
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    protected function stubConsumerMessage(
        string $eventType,
        array $body,
        string $topic = 'catalog.events',
        array $headers = [],
    ): InboundEnvelope {
        $headers = array_merge(['event-type' => $eventType], $headers);
        $stringHeaders = [];
        foreach ($headers as $name => $value) {
            $stringHeaders[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return new InboundEnvelope(
            eventType: $eventType,
            messageId: 'test-'.$eventType,
            topic: $topic,
            key: null,
            sagaId: null,
            payload: $body,
            headers: $stringHeaders,
        );
    }
}
