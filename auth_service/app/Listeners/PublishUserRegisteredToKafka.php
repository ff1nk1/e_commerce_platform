<?php

declare(strict_types=1);

namespace App\Listeners;

use App\DTO\Events\UserRegisteredPayload;
use App\Events\UserRegistered;
use Shop\Messaging\OutboundMessage;
use Shop\Messaging\OutboxPublisher;
use Shop\Messaging\Topics;

final class PublishUserRegisteredToKafka
{
    public function __construct(
        private OutboxPublisher $outbox,
    ) {}

    public function handle(UserRegistered $event): void
    {
        $payload = UserRegisteredPayload::fromUser($event->user);

        $this->outbox->publish(new OutboundMessage(
            topic: Topics::USER_EVENTS,
            key: (string) $payload->userId,
            eventType: UserRegisteredPayload::EVENT_TYPE,
            payload: $payload->toArray(),
            source: 'auth-service',
        ));
    }
}
