<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Messaging\OrderNotification;
use App\Services\KafkaMailPipeline;
use Ecotone\Modelling\Attribute\EventHandler;
use Shop\Messaging\EnvelopeMessage;

final class OrderEventsHandler
{
    public const ALLOWED_TYPES = ['order.paid', 'order.confirmed', 'order.cancelled'];

    public function __construct(
        private KafkaMailPipeline $pipeline,
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
            self::ALLOWED_TYPES,
            (string) config('kafka.topics.order_events', 'order.events'),
            (string) config('kafka.topics.order_events_dlq', 'order.events.dlq'),
        );
    }
}
