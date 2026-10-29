<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Shop\Messaging\Console\ConsumeKafkaCommand;
use Shop\Messaging\Topics;

#[Description('Консьюмер для топика order.events')]
#[Signature('kafka:consume:order-events')]
final class KafkaConsumeOrderEvents extends ConsumeKafkaCommand
{
    protected function topics(): array
    {
        return [Topics::ORDER_EVENTS];
    }

    protected function group(): string
    {
        return (string) config('kafka.consumer_groups.order', 'notification-service-order-v1');
    }
}
