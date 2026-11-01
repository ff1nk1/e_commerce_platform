<?php

declare(strict_types=1);

namespace App\DTO\Stock;

final readonly class StockData
{
    public function __construct(
        public int $productId,
        public int $storeId,
        public int $quantity,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productId: (int) $data['product_id'],
            storeId: (int) $data['store_id'],
            quantity: (int) $data['quantity'],
        );
    }

    /**
     * @return array{product_id: int, store_id: int, quantity: int, reserved: int}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'store_id' => $this->storeId,
            'quantity' => $this->quantity,
            'reserved' => 0,
        ];
    }
}
