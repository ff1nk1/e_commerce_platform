<?php

namespace App\Services\Order;

use App\Models\Order;

class OrderPayloadFactory
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function notificationPayload(Order $order, array $extra = []): array
    {
        $order->loadMissing('items.productSnapshot');

        $payload = [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'email' => $order->customer_email,
            'name' => $order->customer_name,
            'status' => $order->statusEnum()->value,
            'total_amount' => (string) $order->total_amount,
            'items' => $this->notificationItems($order),
            'occurred_at' => now()->toIso8601String(),
        ];

        if (is_string($order->saga_id) && $order->saga_id !== '') {
            $payload['saga_id'] = $order->saga_id;
        }

        return array_merge($payload, $extra);
    }

    /**
     * @return list<array{product_id: int, store_id: int, quantity: int}>
     */
    public function itemsPayload(Order $order): array
    {
        $order->loadMissing('items.productSnapshot');

        $items = [];
        foreach ($order->items as $item) {
            $storeId = $item->productSnapshot?->store_id;
            if ($storeId === null) {
                continue;
            }

            $items[] = [
                'product_id' => (int) $item->product_id,
                'store_id' => (int) $storeId,
                'quantity' => (int) $item->quantity,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOrder(Order $order): array
    {
        $order->loadMissing(['items.productSnapshot', 'payments']);

        $data = $order->toArray();
        $data['status'] = $order->statusEnum()->value;

        return $data;
    }

    /**
     * @return list<array{product_id: int, name: string|null, quantity: int, unit_price: string}>
     */
    private function notificationItems(Order $order): array
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
