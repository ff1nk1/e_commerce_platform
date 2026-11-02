<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Enums\NotificationEventType;
use App\Messaging\OrderNotification;
use App\ValueObjects\AllowedEventTypes;
use Ecotone\Modelling\Attribute\EventHandler;
use Shop\Messaging\EnvelopeMessage;

final class OrderEventsHandler
{
    private function allowedTypes(): AllowedEventTypes
    {
        return AllowedEventTypes::of(
            NotificationEventType::OrderPaid,
            NotificationEventType::OrderConfirmed,
            NotificationEventType::OrderCancelled,
        );
    }

    public function __construct(
        private NotificationEventsHandler $pipeline,
    ) {}

    #[EventHandler(endpointId: 'notification.order')]
    public function onInbound(OrderNotification $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        $this->pipeline->handle(
            $message,
            $this->allowedTypes(),
            (string) config('kafka.topics.order_events', 'order.events'),
            (string) config('kafka.topics.order_events_dlq', 'order.events.dlq'),
        );
    }
}
