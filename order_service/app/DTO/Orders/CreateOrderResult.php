<?php

declare(strict_types=1);

namespace App\DTO\Orders;

final readonly class CreateOrderResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {}
}
