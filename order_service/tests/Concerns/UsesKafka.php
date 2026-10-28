<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Request;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Facades\Kafka;
use Tests\Fakes\StubConsumerMessage;

trait UsesKafka
{
    protected function kafkaBrokers(): string
    {
        $fromEnv = Env::get('KAFKA_BROKERS', Request::server('KAFKA_BROKERS') ?? getenv('KAFKA_BROKERS'));
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return (string) config('kafka.brokers', 'kafka:29092');
    }

    /**
     * Fake Kafka broker for outbox publish assertions (avoids flaky consume / live workers).
     */
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
        string $topic = 'stock.events',
        array $headers = [],
    ): ConsumerMessage {
        $headers = array_merge(['event-type' => $eventType], $headers);

        return new StubConsumerMessage($eventType, $body, $topic, $headers);
    }
}
