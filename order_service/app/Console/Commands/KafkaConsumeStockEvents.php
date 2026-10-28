<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Kafka\Handlers\StockEventsHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Junges\Kafka\Facades\Kafka;

#[Description('Консьюмер Order Service для stock.reserved / stock.reservation_failed')]
#[Signature('kafka:consume:stock-events')]
final class KafkaConsumeStockEvents extends Command
{
    public function handle(): int
    {
        $this->info('Запуск консьюмера для топика stock.events...');

        $consumer = Kafka::consumer(['stock.events'])
            ->withBrokers(config('kafka.brokers', 'kafka:29092'))
            ->withConsumerGroupId('order-service-stock-v1')
            ->withHandler(resolve(StockEventsHandler::class))
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
