<?php

declare(strict_types=1);

namespace Shop\Messaging;

interface MessagePublisher
{
    public function publish(OutboundMessage $message): void;
}
