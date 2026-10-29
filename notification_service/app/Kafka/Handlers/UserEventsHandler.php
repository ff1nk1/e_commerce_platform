<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Messaging\UserNotification;
use App\Services\KafkaMailPipeline;
use Ecotone\Modelling\Attribute\EventHandler;
use Shop\Messaging\EnvelopeMessage;

final class UserEventsHandler
{
    public const ALLOWED_TYPES = ['user.registered'];

    public function __construct(
        private KafkaMailPipeline $pipeline,
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
            self::ALLOWED_TYPES,
            (string) config('kafka.topics.user_events', 'user.events'),
            (string) config('kafka.topics.user_events_dlq', 'user.events.dlq'),
        );
    }
}
