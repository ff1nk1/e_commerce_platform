<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Shop\Messaging\Console\ConsumeKafkaCommand;
use Shop\Messaging\Topics;

#[Description('Консьюмер для топика user.events')]
#[Signature('kafka:consume:user-events')]
final class KafkaConsumeUserEvents extends ConsumeKafkaCommand
{
    protected function topics(): array
    {
        return [Topics::USER_EVENTS];
    }

    protected function group(): string
    {
        return (string) config('kafka.consumer_groups.user', 'notification-service-consumers-v1');
    }
}
