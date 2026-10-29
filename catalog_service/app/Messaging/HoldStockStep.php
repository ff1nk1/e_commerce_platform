<?php

declare(strict_types=1);

namespace App\Messaging;

final class HoldStockStep
{
    public function __construct(
        public string $sagaId,
        public string $step,
    ) {}
}
