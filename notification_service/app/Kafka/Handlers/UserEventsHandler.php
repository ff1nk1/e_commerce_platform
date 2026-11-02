<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Enums\NotificationEventType;
use App\Messaging\UserNotification;
use App\ValueObjects\AllowedEventTypes;
use Ecotone\Modelling\Attribute\EventHandler;
use Shop\Messaging\EnvelopeMessage;

final class UserEventsHandler
{
    private function allowedTypes(): AllowedEventTypes
    {
        return AllowedEventTypes::of(NotificationEventType::UserRegistered);
    }

    public function __construct(
        private NotificationEventsHandler $pipeline,
    ) {}

    #[EventHandler(endpointId: 'notification.user')]
    public function onInbound(UserNotification $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        $this->pipeline->handle(
            $message,
            $this->allowedTypes(),
            (string) config('kafka.topics.user_events', 'user.events'),
            (string) config('kafka.topics.user_events_dlq', 'user.events.dlq'),
        );
    }
}
