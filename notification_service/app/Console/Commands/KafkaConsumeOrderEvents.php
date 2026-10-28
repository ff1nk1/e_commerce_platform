<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Kafka\Handlers\OrderEventsHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Консьюмер для топика order.events')]
#[Signature('kafka:consume:order-events')]
final class KafkaConsumeOrderEvents extends AbstractKafkaConsumeCommand
{
    protected function topic(): string
    {
        return (string) config('kafka.topics.order_events', 'order.events');
    }

    protected function groupId(): string
    {
        return (string) config('kafka.consumer_groups.order', 'notification-service-order-v1');
    }

    protected function handlerClass(): string
    {
        return OrderEventsHandler::class;
    }
}
