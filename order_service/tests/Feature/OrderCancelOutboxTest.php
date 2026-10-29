<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class OrderCancelOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_awaiting_payment_writes_cancelled_and_release(): void
    {
        ProductSnapshot::query()->create([
            'id' => 5,
            'name' => 'X',
            'price' => '1.00',
            'store_id' => 9,
        ]);

        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 2,
            'customer_email' => 'cancel@example.com',
            'customer_name' => 'Cancel User',
            'status' => 'awaiting_payment',
            'total_amount' => '1.00',
        ]);
        $order->items()->create([
            'product_id' => 5,
            'quantity' => 3,
            'unit_price' => '1.00',
        ]);

        $this->postJson('/api/orders/'.$order->id.'/cancel', ['user_id' => 2])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('cancel_reason', 'user_cancelled');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'cancel_reason' => 'user_cancelled',
        ]);

        $cancelled = OutboxQueue::first('order.cancelled');
        $release = OutboxQueue::first('stock.release_requested');
        $this->assertNotNull($cancelled);
        $this->assertNotNull($release);
        $this->assertSame($sagaId, $cancelled->payload['saga_id']);
        $this->assertSame('cancel@example.com', $cancelled->payload['email']);
        $this->assertSame('user_cancelled', $cancelled->payload['reason']);
        $this->assertSame('order.cancelled', $cancelled->payload['event_type'] ?? null);
        $this->assertIsString($cancelled->payload['occurred_at'] ?? null);
        $this->assertNotSame('', $cancelled->payload['occurred_at']);
        $this->assertSame($sagaId, $release->payload['saga_id']);
        $this->assertSame('stock.release_requested', $release->payload['event_type'] ?? null);
    }

    public function test_cancel_pending_does_not_request_stock_release(): void
    {
        ProductSnapshot::query()->create([
            'id' => 6,
            'name' => 'Y',
            'price' => '1.00',
            'store_id' => 9,
        ]);

        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 2,
            'customer_email' => 'pending-cancel@example.com',
            'customer_name' => 'Pending Cancel',
            'status' => 'pending',
            'total_amount' => '1.00',
        ]);
        $order->items()->create([
            'product_id' => 6,
            'quantity' => 1,
            'unit_price' => '1.00',
        ]);

        $this->postJson('/api/orders/'.$order->id.'/cancel', ['user_id' => 2])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('cancel_reason', 'user_cancelled');

        $this->assertNotNull(OutboxQueue::first('order.cancelled'));
        $this->assertSame(
            0,
            OutboxQueue::count('stock.release_requested')
        );
    }
}
