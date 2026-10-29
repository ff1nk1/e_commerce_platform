<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Order;
use Illuminate\Support\Str;
use Shop\Messaging\OutboxQueue;

final class OutboxPostgresTransactionTest extends IntegrationTestCase
{
    public function test_create_persists_order_items_and_outbox_atomically(): void
    {
        $this->fakeKafka();
        $this->seedSnapshot(501, 'Phone', '100.00', 2);

        $response = $this->postJson(
            '/api/orders',
            $this->orderCreatePayload(items: [['product_id' => 501, 'quantity' => 2]]),
            ['Idempotency-Key' => 'it-txn-'.Str::uuid()]
        );

        $response->assertCreated();
        $orderId = (int) $response->json('id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'customer_email' => 'integration@example.com',
            'status' => 'pending',
            'total_amount' => '200.00',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => 501,
            'quantity' => 2,
        ]);

        $event = OutboxQueue::first('order.created');
        $this->assertNotNull($event);
        $this->assertSame('order.events', $event->topic);
        $this->assertSame($orderId, $event->payload['order_id']);
        $this->assertSame([
            [
                'product_id' => 501,
                'store_id' => 2,
                'store_name' => null,
                'category_id' => null,
                'category_name' => null,
                'category_slug' => null,
                'quantity' => 2,
                'unit_price' => '100.00',
            ],
        ], $event->payload['items']);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame($order->saga_id, $event->payload['saga_id']);

        $published = OutboxQueue::flush();
        $this->assertGreaterThanOrEqual(1, $published);
        $this->assertSame(0, OutboxQueue::count());
    }
}
