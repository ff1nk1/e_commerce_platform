<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class StockChanged
{
    use Dispatchable;

    /**
     * @param  array{product_id: int, store_id: int, quantity: int}  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}
}
