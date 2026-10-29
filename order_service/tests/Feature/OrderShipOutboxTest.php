<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class OrderShipOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_ship_confirmed_writes_order_shipped_outbox(): void
    {
        ProductSnapshot::query()->create([
            'id' => 5,
            'name' => 'X',
            'price' => '10.00',
            'store_id' => 1,
        ]);

        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 7,
            'customer_email' => 'ship@example.com',
            'customer_name' => 'Ship User',
            'status' => 'confirmed',
            'total_amount' => '10.00',
        ]);
        $order->items()->create([
            'product_id' => 5,
            'quantity' => 1,
            'unit_price' => '10.00',
        ]);

        $this->postJson('/api/admin/orders/'.$order->id.'/ship')
            ->assertOk()
            ->assertJsonPath('status', 'shipped');

        $shipped = OutboxQueue::first('order.shipped');
        $this->assertNotNull($shipped);
        $this->assertSame('order.events', $shipped->topic);
        $this->assertSame($order->id, $shipped->payload['order_id']);
        $this->assertSame(7, $shipped->payload['user_id']);
        $this->assertSame('10.00', $shipped->payload['total_amount']);
        $this->assertSame('shipped', $shipped->payload['status']);
        $this->assertSame('order.shipped', $shipped->payload['event_type'] ?? null);
        $this->assertIsString($shipped->payload['occurred_at'] ?? null);
        $this->assertSame($sagaId, $shipped->payload['saga_id']);
    }

    public function test_ship_rejects_non_confirmed_status(): void
    {
        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 7,
            'customer_email' => 'pending@example.com',
            'customer_name' => 'Pending',
            'status' => 'pending',
            'total_amount' => '1.00',
        ]);

        $this->postJson('/api/admin/orders/'.$order->id.'/ship')->assertUnprocessable();

        $this->assertSame(0, OutboxQueue::count('order.shipped'));
    }
}
