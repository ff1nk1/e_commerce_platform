<?php

declare(strict_types=1);

namespace App\DTO\Orders;

final readonly class CreateOrderData
{
    /**
     * @param  list<OrderItemRequest>  $items
     */
    public function __construct(
        public int $userId,
        public string $email,
        public string $name,
        public array $items,
        public string $idempotencyKey,
    ) {}
}
