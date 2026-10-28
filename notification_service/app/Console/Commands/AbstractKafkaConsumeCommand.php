<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Junges\Kafka\Facades\Kafka;

abstract class AbstractKafkaConsumeCommand extends Command
{
    abstract protected function topic(): string;

    abstract protected function groupId(): string;

    /**
     * @return class-string
     */
    abstract protected function handlerClass(): string;

    public function handle(): int
    {
        $topic = $this->topic();
        $this->info("Запуск консьюмера для топика {$topic}...");

        $consumer = Kafka::consumer([$topic])
            ->withBrokers(config('kafka.brokers', 'kafka:29092'))
            ->withConsumerGroupId($this->groupId())
            ->withHandler(resolve($this->handlerClass()))
            ->withAutoCommit()
            ->withOptions([
                'auto.offset.reset' => 'earliest',
            ])
            ->build();

        try {
            $consumer->consume();
        } catch (\Throwable $e) {
            $this->error('ОШИБКА KAFKA: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
