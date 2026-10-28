<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsKafkaConsumer;
use App\Kafka\Handlers\ProductEventsHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Консьюмер Search Service для product.created / product.updated / product.deleted')]
#[Signature('kafka:consume:product-events')]
final class KafkaConsumeProductEvents extends Command
{
    use RunsKafkaConsumer;

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
