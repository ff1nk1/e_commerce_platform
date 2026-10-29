<?php

declare(strict_types=1);

namespace Shop\Messaging;

interface InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object;
}
