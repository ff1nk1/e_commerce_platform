<?php

declare(strict_types=1);

namespace App\Listeners;

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
        $user = $event->user;
        $registeredAt = $user->created_at?->toIso8601String() ?? now()->toIso8601String();

        $this->outbox->publish(new OutboundMessage(
            topic: Topics::USER_EVENTS,
            key: (string) $user->id,
            eventType: 'user.registered',
            payload: [
                'user_id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'registered_at' => $registeredAt,
                'event_type' => 'user.registered',
                'event' => 'user.registered',
            ],
            source: 'auth-service',
        ));
    }
}
