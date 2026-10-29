<?php

declare(strict_types=1);

namespace Shop\Messaging\Ecotone;

use Ecotone\Modelling\EventBus;
use Shop\Messaging\OutboundMessage;
use Shop\Messaging\OutboxPublisher;

final class EcotoneOutboxPublisher implements OutboxPublisher
{
    public function __construct(
        private readonly EventBus $bus,
    ) {}

    public function publish(OutboundMessage $message): void
    {
        $this->bus->publish($message);
    }
}
