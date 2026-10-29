<?php

declare(strict_types=1);

namespace App\Messaging;

use Shop\Messaging\InboundEnvelope;

final class StockReserved
{
    public string $sagaId;

    public function __construct(
        public InboundEnvelope $envelope,
    ) {
        $this->sagaId = $envelope->sagaId ?? '';
    }
}
