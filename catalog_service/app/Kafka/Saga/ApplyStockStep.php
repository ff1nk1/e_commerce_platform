<?php

declare(strict_types=1);

namespace App\Kafka\Saga;

use App\Enums\SagaStep;

final class ApplyStockStep
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public string $sagaId,
        public SagaStep $step,
        public int|string $orderId,
        public array $items,
    ) {}
}
