<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Shop\Messaging\Console\ConsumeKafkaCommand;
use Shop\Messaging\Topics;

#[Description('Консьюмер Recommendation Service для catalog.events')]
#[Signature('kafka:consume:stock-events')]
final class KafkaConsumeStockEvents extends ConsumeKafkaCommand
{
    protected function topics(): array
    {
        return [Topics::CATALOG_EVENTS];
    }

    protected function group(): string
    {
        return (string) config('kafka.consumer_groups.stock', 'recommendation-service-stock-v1');
    }
}
