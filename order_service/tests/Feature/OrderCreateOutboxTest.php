<?php

namespace Tests\Feature;

use App\Models\OutboxEvent;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BindsInMemoryIdempotency;
use Tests\Concerns\BindsInMemorySagaStepGuard;
use Tests\TestCase;

class OrderCreateOutboxTest extends TestCase
{
    use BindsInMemoryIdempotency;
    use BindsInMemorySagaStepGuard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInMemoryIdempotency();
        $this->bindInMemorySagaStepGuard();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_writes_order_created_outbox_with_store_id(): void
    {
        ProductSnapshot::query()->create([
            'id' => 42,
            'name' => 'Phone',
            'price' => '199.99',
            'store_id' => 7,
            'store_name' => 'Main Store',
            'category_id' => 3,
            'category_name' => 'Gadgets',
            'category_slug' => 'gadgets',
        ]);

        $response = $this->postJson('/api/orders', [
            'user_id' => 3,
            'email' => 'buyer@example.com',
            'name' => 'Buyer',
            'items' => [
                ['product_id' => 42, 'quantity' => 2],
            ],
        ], [
            'Idempotency-Key' => 'key-create-1',
        ]);

        $response->assertCreated();
        $orderId = $response->json('id');

        $event = OutboxEvent::query()->where('event_type', 'order.created')->first();
        $this->assertNotNull($event);
        $this->assertSame('order.events', $event->topic);
        $this->assertSame($orderId, $event->payload['order_id']);
        $this->assertSame('order.created', $event->payload['event_type'] ?? null);
        $this->assertSame(3, $event->payload['user_id'] ?? null);
        $this->assertSame('399.98', $event->payload['total_amount'] ?? null);
        $this->assertIsString($event->payload['created_at'] ?? null);
        $this->assertNotSame('', $event->payload['created_at']);
        $this->assertIsString($event->payload['saga_id'] ?? null);
        $this->assertNotSame('', $event->payload['saga_id']);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'saga_id' => $event->payload['saga_id'],
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer',
        ]);
        $this->assertSame([
            [
                'product_id' => 42,
                'store_id' => 7,
                'store_name' => 'Main Store',
                'category_id' => 3,
                'category_name' => 'Gadgets',
                'category_slug' => 'gadgets',
                'quantity' => 2,
                'unit_price' => '199.99',
            ],
        ], $event->payload['items']);
        $this->assertArrayHasKey($event->payload['saga_id'].':order.created', $this->sagaStepStore);
    }

    public function test_idempotent_create_does_not_write_second_outbox(): void
    {
        ProductSnapshot::query()->create([
            'id' => 42,
            'name' => 'Phone',
            'price' => '10.00',
            'store_id' => 1,
        ]);

        $headers = ['Idempotency-Key' => 'key-dup-1'];
        $payload = [
            'user_id' => 3,
            'email' => 'buyer@example.com',
            'name' => 'Buyer',
            'items' => [
                ['product_id' => 42, 'quantity' => 1],
            ],
        ];

        $first = $this->postJson('/api/orders', $payload, $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/orders', $payload, $headers);
        $second->assertCreated();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.created')->count());
    }

    public function test_same_idempotency_key_different_user_does_not_leak(): void
    {
        ProductSnapshot::query()->create([
            'id' => 42,
            'name' => 'Phone',
            'price' => '10.00',
            'store_id' => 1,
        ]);

        $headers = ['Idempotency-Key' => 'shared-key'];

        $first = $this->postJson('/api/orders', [
            'user_id' => 3,
            'email' => 'a@example.com',
            'name' => 'A',
            'items' => [['product_id' => 42, 'quantity' => 1]],
        ], $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/orders', [
            'user_id' => 9,
            'email' => 'b@example.com',
            'name' => 'B',
            'items' => [['product_id' => 42, 'quantity' => 1]],
        ], $headers);
        $second->assertCreated();

        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertSame(3, $first->json('user_id'));
        $this->assertSame(9, $second->json('user_id'));
        $this->assertSame(2, OutboxEvent::query()->where('event_type', 'order.created')->count());
    }
}
