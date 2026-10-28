<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsKafkaConsumer;
use App\Kafka\Handlers\CategoryEventsHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Консьюмер Search Service для category.created / category.updated / category.deleted')]
#[Signature('kafka:consume:category-events')]
final class KafkaConsumeCategoryEvents extends Command
{
    use RunsKafkaConsumer;

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
