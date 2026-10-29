<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Shop\Messaging\Console\ConsumeKafkaCommand;
use Shop\Messaging\Topics;

#[Description('Консьюмер Search Service для category.events')]
#[Signature('kafka:consume:category-events')]
final class KafkaConsumeCategoryEvents extends ConsumeKafkaCommand
{
    protected function topics(): array
    {
        return [Topics::CATEGORY_EVENTS];
    }

    protected function group(): string
    {
        return (string) config('kafka.consumer_groups.categories', 'search-service-categories-v1');
    }
}
