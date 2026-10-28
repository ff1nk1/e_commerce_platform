<?php

declare(strict_types=1);

namespace App\Services\Kafka;

use Junges\Kafka\Facades\Kafka;

final class KafkaPublisher
{
    private const SOCKET_TIMEOUT_MS = 60000;

    private const MESSAGE_TIMEOUT_MS = 60000;

    private const REQUEST_TIMEOUT_MS = 30000;

    private const PUBLISH_RETRIES = 10;

    private const RETRY_BACKOFF_MS = 1000;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    public function publish(string $topic, string $key, array $headers, array $body): void
    {
        Kafka::publish(config('kafka.brokers', 'kafka:29092'))
            ->onTopic($topic)
            ->withConfigOptions([
                'socket.timeout.ms' => self::SOCKET_TIMEOUT_MS,
                'message.timeout.ms' => self::MESSAGE_TIMEOUT_MS,
                'request.timeout.ms' => self::REQUEST_TIMEOUT_MS,
                'retries' => self::PUBLISH_RETRIES,
                'retry.backoff.ms' => self::RETRY_BACKOFF_MS,
            ])
            ->withKafkaKey($key)
            ->withHeaders($headers)
            ->withBody($body)
            ->send();
    }
}
