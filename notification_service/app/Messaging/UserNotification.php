<?php

declare(strict_types=1);

namespace App\Messaging;

use Shop\Messaging\InboundEnvelope;

final class UserNotification
{
    public function __construct(
        public InboundEnvelope $envelope,
    ) {}
}
