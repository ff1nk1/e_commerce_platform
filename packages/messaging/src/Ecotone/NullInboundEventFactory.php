<?php

declare(strict_types=1);

namespace Shop\Messaging\Ecotone;

use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class NullInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return null;
    }
}
