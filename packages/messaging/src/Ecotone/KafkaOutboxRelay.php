<?php

declare(strict_types=1);

namespace Shop\Messaging\Ecotone;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Shop\Messaging\MessagePublisher;
use Shop\Messaging\OutboundMessage;

final class KafkaOutboxRelay
{
    public function __construct(
        private readonly MessagePublisher $publisher,
    ) {}

    #[Asynchronous('kafka-outbox')]
    #[EventHandler(endpointId: 'shop.messaging.relayOutbound')]
    public function relay(OutboundMessage $message): void
    {
        $this->publisher->publish($message);
    }
}
