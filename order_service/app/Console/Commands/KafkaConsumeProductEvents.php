<?php

namespace App\Console\Commands;

use App\Kafka\Handlers\ProductEventsHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Junges\Kafka\Facades\Kafka;

class KafkaConsumeProductEvents extends Command
{
    private const MIGRATION_WAIT_SLEEP_SECONDS = 5;

    protected $signature = 'kafka:consume:product-events';

    protected $description = 'Консьюмер Order Service для снапшотов product.created / product.updated';

    public function handle(): int
    {
        $this->info('Запуск консьюмера для топиков product.created и product.updated...');

        while (! Schema::hasTable('product_snapshots')) {
            $this->warn('Таблица product_snapshots ещё нет — ждём миграции (sleep 5s)...');
            sleep(self::MIGRATION_WAIT_SLEEP_SECONDS);
        }

        $consumer = Kafka::consumer(['product.created', 'product.updated'])
            ->withBrokers(config('kafka.brokers', 'kafka:29092'))
            ->withConsumerGroupId('order-service-products-v2')
            ->withHandler(app(ProductEventsHandler::class))
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
