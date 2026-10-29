<?php

declare(strict_types=1);

namespace App\Messaging;

use Ecotone\Modelling\AggregateNotFoundException;
use Ecotone\Modelling\EventBus;

final class FulfillmentEvents
{
    public function __construct(
        private EventBus $bus,
    ) {}

    public function publish(object $event, string $sagaId): void
    {
        if ($sagaId === '') {
            return;
        }

        try {
            $this->bus->publish($event);
        } catch (AggregateNotFoundException) {
            $this->bus->publish(new OrderPlaced($sagaId));
            $this->bus->publish($event);
        }
    }
}
