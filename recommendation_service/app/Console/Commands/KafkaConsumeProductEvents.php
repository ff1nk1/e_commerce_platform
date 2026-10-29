<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Shop\Messaging\Console\ConsumeKafkaCommand;
use Shop\Messaging\Topics;

#[Description('Консьюмер Recommendation Service для product.events')]
#[Signature('kafka:consume:product-events')]
final class KafkaConsumeProductEvents extends ConsumeKafkaCommand
{
    protected function topics(): array
    {
        return [Topics::PRODUCT_EVENTS];
    }

    protected function group(): string
    {
        return (string) config('kafka.consumer_groups.products', 'recommendation-service-products-v1');
    }
}
