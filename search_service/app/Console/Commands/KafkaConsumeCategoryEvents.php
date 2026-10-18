<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsKafkaConsumer;
use App\Kafka\Handlers\CategoryEventsHandler;
use Illuminate\Console\Command;

class KafkaConsumeCategoryEvents extends Command
{
    use RunsKafkaConsumer;

    protected $signature = 'kafka:consume:category-events';

    protected $description = 'Консьюмер Search Service для category.created / category.updated / category.deleted';

    public function handle(): int
    {
        return $this->runKafkaConsumer(
            ['category.created', 'category.updated', 'category.deleted'],
            'search-service-categories-v1',
            CategoryEventsHandler::class,
            'Запуск консьюмера для топиков category.created, category.updated, category.deleted...',
        );
    }
}
