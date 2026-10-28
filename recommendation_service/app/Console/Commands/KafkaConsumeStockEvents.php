<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Kafka\Handlers\StockEventsHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Консьюмер Recommendation Service для stock.changed на catalog.events')]
#[Signature('kafka:consume:stock-events')]
final class KafkaConsumeStockEvents extends AbstractKafkaConsumeCommand
{
    protected function topics(): array
    {
        return [
            (string) config('kafka.topics.catalog_events', 'catalog.events'),
        ];
    }

    protected function groupId(): string
    {
        return (string) config('kafka.consumer_groups.stock', 'recommendation-service-stock-v1');
    }

    protected function handlerClass(): string
    {
        return StockEventsHandler::class;
    }
}
