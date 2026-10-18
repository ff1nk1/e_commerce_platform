<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsKafkaConsumer;
use App\Kafka\Handlers\ProductEventsHandler;
use Illuminate\Console\Command;

class KafkaConsumeProductEvents extends Command
{
    use RunsKafkaConsumer;

    protected $signature = 'kafka:consume:product-events';

    protected $description = 'Консьюмер Search Service для product.created / product.updated / product.deleted';

    public function handle(): int
    {
        return $this->runKafkaConsumer(
            ['product.created', 'product.updated', 'product.deleted'],
            'search-service-products-v1',
            ProductEventsHandler::class,
            'Запуск консьюмера для топиков product.created, product.updated, product.deleted...',
        );
    }
}
