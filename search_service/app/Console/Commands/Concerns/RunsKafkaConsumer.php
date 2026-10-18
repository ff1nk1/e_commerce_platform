<?php

namespace App\Console\Commands\Concerns;

use Junges\Kafka\Facades\Kafka;
use Throwable;

trait RunsKafkaConsumer
{
    /**
     * @param  list<string>  $topics
     * @param  class-string|object  $handler
     */
    protected function runKafkaConsumer(
        array $topics,
        string $groupId,
        object|string $handler,
        string $infoMessage,
    ): int {
        $this->info($infoMessage);

        $resolved = is_string($handler) ? app($handler) : $handler;

        $consumer = Kafka::consumer($topics)
            ->withBrokers(config('kafka.brokers', 'kafka:29092'))
            ->withConsumerGroupId($groupId)
            ->withHandler($resolved)
            ->withAutoCommit()
            ->withOptions([
                'auto.offset.reset' => 'earliest',
            ])
            ->build();

        try {
            $consumer->consume();
        } catch (Throwable $e) {
            $this->error('ОШИБКА KAFKA: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
