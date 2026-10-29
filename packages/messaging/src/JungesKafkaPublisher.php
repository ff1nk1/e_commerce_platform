<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Junges\Kafka\Facades\Kafka;

final class JungesKafkaPublisher implements MessagePublisher
{
    public function __construct(
        private readonly EventCodec $codec,
    ) {}

    /**
     * @return array<string, int>
     */
    public static function producerOptions(): array
    {
        return [
            'socket.timeout.ms' => 60000,
            'message.timeout.ms' => 60000,
            'request.timeout.ms' => 30000,
            'retries' => 10,
            'retry.backoff.ms' => 1000,
        ];
    }

    public function publish(OutboundMessage $message): void
    {
        $encoded = $this->codec->encode($message);
        $brokers = (string) config('kafka.brokers', 'kafka:29092');

        Kafka::publish($brokers)
            ->onTopic($encoded->topic)
            ->withConfigOptions(self::producerOptions())
            ->withKafkaKey($encoded->key)
            ->withHeaders($encoded->headers)
            ->withBody($encoded->body)
            ->send();
    }
}
