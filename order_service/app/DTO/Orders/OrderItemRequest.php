<?php

declare(strict_types=1);

namespace App\DTO\Orders;

final readonly class OrderItemRequest
{
    public function __construct(
        public int $product_id,
        public int $quantity,
    ) {}

    /**
     * @param  list<array{product_id: int|string, quantity: int|string}>  $items
     * @return list<self>
     */
    public static function fromArray(array $items): array
    {
        $requests = [];
        foreach ($items as $item) {
            $requests[] = new self((int) $item['product_id'], (int) $item['quantity']);
        }

        return $requests;
    }
}
