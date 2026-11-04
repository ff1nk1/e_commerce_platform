<?php

declare(strict_types=1);

namespace App\Saga;

use App\Enums\SagaStep;

final class HoldStockStep
{
    public function __construct(
        public string $sagaId,
        public SagaStep $step,
    ) {}
}
