<?php

declare(strict_types=1);

namespace App\Messaging;

use Shop\Messaging\InboundEnvelope;

final class CatalogStockChanged
{
    public function __construct(
        public InboundEnvelope $envelope,
    ) {}
}
