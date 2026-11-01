<?php

declare(strict_types=1);

namespace App\DTO\Orders;

use App\Models\Order;

final readonly class OrderNotification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function fromOrder(Order $order, array $extra = []): self
    {
        $order->loadMissing('items.productSnapshot');

        $payload = [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'email' => $order->customer_email,
            'name' => $order->customer_name,
            'status' => $order->statusEnum()->value,
            'total_amount' => (string) $order->total_amount,
            'items' => self::items($order),
            'occurred_at' => now()->toIso8601String(),
        ];

        if (is_string($order->saga_id) && $order->saga_id !== '') {
            $payload['saga_id'] = $order->saga_id;
        }

        return new self(array_merge($payload, $extra));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * @return list<array{product_id: int, name: string|null, quantity: int, unit_price: string}>
     */
    private static function items(Order $order): array
    {
        $items = [];
        foreach ($order->items as $item) {
            $items[] = [
                'product_id' => (int) $item->product_id,
                'name' => $item->productSnapshot?->name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (string) $item->unit_price,
            ];
        }

        return $items;
    }
}
