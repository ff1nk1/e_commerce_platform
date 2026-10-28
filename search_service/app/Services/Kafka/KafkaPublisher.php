<?php

declare(strict_types=1);

namespace App\Services\Kafka;

use Junges\Kafka\Facades\Kafka;

final class KafkaPublisher
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    public function publish(string $topic, array $headers, array $body, ?string $key = null): void
    {
        $builder = Kafka::publish(config('kafka.brokers', 'kafka:29092'))
            ->onTopic($topic)
            ->withConfigOptions([
                'socket.timeout.ms' => 60000,
                'message.timeout.ms' => 60000,
                'request.timeout.ms' => 30000,
                'retries' => 3,
                'retry.backoff.ms' => 500,
            ])
            ->withHeaders($headers)
            ->withBody($body);

        if ($key !== null) {
            $builder->withKafkaKey($key);
        }

        $builder->send();
    }
}
