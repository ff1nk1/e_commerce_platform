<?php

declare(strict_types=1);

namespace App\DTO\Orders;

use App\Models\Order;

final readonly class StockLine
{
    public function __construct(
        public int $product_id,
        public int $store_id,
        public int $quantity,
    ) {}

    /**
     * @return list<self>
     */
    public static function listFromOrder(Order $order): array
    {
        $order->loadMissing('items.productSnapshot');

        $lines = [];
        foreach ($order->items as $item) {
            $storeId = $item->productSnapshot?->store_id;
            if ($storeId === null) {
                continue;
            }

            $lines[] = new self(
                (int) $item->product_id,
                (int) $storeId,
                (int) $item->quantity,
            );
        }

        return $lines;
    }

    /**
     * @return array{product_id: int, store_id: int, quantity: int}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->product_id,
            'store_id' => $this->store_id,
            'quantity' => $this->quantity,
        ];
    }
}
