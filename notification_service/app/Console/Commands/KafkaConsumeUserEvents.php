<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Kafka\Handlers\UserEventsHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Консьюмер для топика user.events')]
#[Signature('kafka:consume:user-events')]
final class KafkaConsumeUserEvents extends AbstractKafkaConsumeCommand
{
    protected function topic(): string
    {
        return (string) config('kafka.topics.user_events', 'user.events');
    }

    protected function groupId(): string
    {
        return (string) config('kafka.consumer_groups.user', 'notification-service-consumers-v1');
    }

    protected function handlerClass(): string
    {
        return UserEventsHandler::class;
    }
}
