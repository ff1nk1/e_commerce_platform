<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsKafkaConsumer;
use App\Kafka\Handlers\StockEventsHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Консьюмер Search Service для stock.changed на топике catalog.events')]
#[Signature('kafka:consume:stock-events')]
final class KafkaConsumeStockEvents extends Command
{
    use RunsKafkaConsumer;

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
