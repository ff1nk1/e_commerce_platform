<?php

declare(strict_types=1);

namespace Shop\Messaging\Ecotone;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\EventBus;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class InboundDispatcher
{
    public function __construct(
        private readonly InboundEventFactory $events,
        private readonly EventBus $bus,
    ) {}

    #[Asynchronous('inbox')]
    #[EventHandler(endpointId: 'shop.messaging.dispatchInbound')]
    public function dispatch(InboundEnvelope $envelope): void
    {
        $event = $this->events->make($envelope);
        if ($event === null) {
            return;
        }

        $this->bus->publish($event);
    }
}
