<?php

declare(strict_types=1);

namespace App\Messaging;

final class ApplyStockStep
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public string $sagaId,
        public string $step,
        public int|string $orderId,
        public array $items,
    ) {}
}
