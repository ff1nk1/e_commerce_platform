<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Kafka\MainHandlers\OrderEventsHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Junges\Kafka\Facades\Kafka;

#[Description('Консьюмер Catalog Service для обработки событий заказов')]
#[Signature('kafka:consume:order-events')]
final class KafkaConsumeOrderEvents extends Command
{
    public function handle(): int
    {
        $this->info('Запуск консьюмера для топика order.events...');

        $consumer = Kafka::consumer(['order.events'])
            ->withBrokers(config('kafka.brokers', 'kafka:29092'))

            ->withConsumerGroupId('catalog-service-orders-v1')
            ->withHandler(resolve(OrderEventsHandler::class))
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
