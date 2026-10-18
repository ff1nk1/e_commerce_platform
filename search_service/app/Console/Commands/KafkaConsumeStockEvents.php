<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsKafkaConsumer;
use App\Kafka\Handlers\StockEventsHandler;
use Illuminate\Console\Command;

class KafkaConsumeStockEvents extends Command
{
    use RunsKafkaConsumer;

    protected $signature = 'kafka:consume:stock-events';

    protected $description = 'Консьюмер Search Service для stock.changed на топике catalog.events';

    public function handle(): int
    {
        return $this->runKafkaConsumer(
            ['catalog.events'],
            'search-service-stock-v1',
            StockEventsHandler::class,
            'Запуск консьюмера для топика catalog.events (stock.changed)...',
        );
    }
}
