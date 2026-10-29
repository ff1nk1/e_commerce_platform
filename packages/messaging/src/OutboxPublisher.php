<?php

declare(strict_types=1);

namespace Shop\Messaging;

interface OutboxPublisher
{
    public function publish(OutboundMessage $message): void;
}
