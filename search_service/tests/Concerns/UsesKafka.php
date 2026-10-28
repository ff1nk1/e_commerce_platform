<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Facades\Kafka;
use Tests\Fakes\StubConsumerMessage;

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
    ): ConsumerMessage {
        $headers = array_merge(['event-type' => $eventType], $headers);

        return new StubConsumerMessage($eventType, $body, $topic, $headers);
    }
}
