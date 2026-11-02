<?php

declare(strict_types=1);

namespace App\DTO\Orders;

use App\Models\OrderItem;

final readonly class OrderLineSnapshot
{
    public function __construct(
        public int $productId,
        public int $storeId,
        public ?string $storeName,
        public ?int $categoryId,
        public ?string $categoryName,
        public ?string $categorySlug,
        public int $quantity,
        public string $unitPrice,
    ) {}

    /**
     * @return array{product_id: int, store_id: int, store_name: string|null, category_id: int|null, category_name: string|null, category_slug: string|null, quantity: int, unit_price: string}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'store_id' => $this->storeId,
            'store_name' => $this->storeName,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'category_slug' => $this->categorySlug,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
        ];
    }

    /**
     * @return array{product_id: int, quantity: int, unit_price: string}
     */
    public function toOrderItemAttributes(): array
    {
        return [
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
        ];
    }

    public function applyTo(OrderItem $item): void
    {
        $item->product_id = $this->productId;
        $item->quantity = $this->quantity;
        $item->unit_price = $this->unitPrice;
    }
}
