<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Shop\Messaging\Console\ConsumeKafkaCommand;
use Shop\Messaging\KafkaInboundAdapter;
use Shop\Messaging\Topics;

#[Description('Консьюмер Order Service для product.events')]
#[Signature('kafka:consume:product-events')]
final class KafkaConsumeProductEvents extends ConsumeKafkaCommand
{
    public function handle(KafkaInboundAdapter $adapter): int
    {
        while (! Schema::hasTable('product_snapshots')) {
            $this->warn('product_snapshots table missing; waiting for migrations');
            Sleep::sleep(5);
        }

        return parent::handle($adapter);
    }

    protected function topics(): array
    {
        return [Topics::PRODUCT_EVENTS];
    }

    protected function group(): string
    {
        return (string) config('kafka.consumer_groups.products', 'order-service-products-v2');
    }
}
