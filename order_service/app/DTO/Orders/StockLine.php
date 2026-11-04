<?php

declare(strict_types=1);

namespace App\DTO\Orders;

use App\Models\Order;

/**
 * A release/commit line.
 *
 * It carries no store_id: the warehouse is owned by the catalog and read from
 * the held reservation (see catalog StockReservationLedger::settleHeld), so the
 * order must not re-derive it from the mutable product snapshot.
 */
final readonly class StockLine
{
    public function __construct(
        public int $product_id,
        public int $quantity,
    ) {}

    /**
     * @return list<self>
     */
    public static function listFromOrder(Order $order): array
    {
        $order->loadMissing('items');

        $lines = [];
        foreach ($order->items as $item) {
            $lines[] = new self(
                (int) $item->product_id,
                (int) $item->quantity,
            );
        }

        return $lines;
    }

    /**
     * @return array{product_id: int, quantity: int}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
        ];
    }
}
