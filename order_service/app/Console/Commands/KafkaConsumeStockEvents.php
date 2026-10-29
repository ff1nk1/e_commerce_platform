<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Shop\Messaging\Console\ConsumeKafkaCommand;
use Shop\Messaging\Topics;

#[Description('Консьюмер Order Service для stock.events')]
#[Signature('kafka:consume:stock-events')]
final class KafkaConsumeStockEvents extends ConsumeKafkaCommand
{
    protected function topics(): array
    {
        return [Topics::STOCK_EVENTS];
    }

    protected function group(): string
    {
        return (string) config('kafka.consumer_groups.stock', 'order-service-stock-v1');
    }
}
