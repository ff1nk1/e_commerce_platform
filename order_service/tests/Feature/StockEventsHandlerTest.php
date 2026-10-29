<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Shop\Messaging\OutboxQueue;
use Tests\Concerns\UsesKafka;
use Tests\TestCase;

final class StockEventsHandlerTest extends TestCase
{
    use RefreshDatabase;
    use UsesKafka;

    public function test_stock_reserved_moves_pending_order_to_awaiting_payment(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        $this->deliverStock('stock.reserved', [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        $this->assertSame('awaiting_payment', $order->fresh()->status->value);
    }

    public function test_stock_reserved_without_saga_id_is_ignored(): void
    {
        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 1,
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        $this->deliverStock('stock.reserved', ['order_id' => $order->id, 'status' => 'success']);

        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_stock_reserved_with_mismatched_saga_id_is_ignored(): void
    {
        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 1,
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        $this->deliverStock('stock.reserved', [
            'saga_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_reservation_failed_cancels_order_and_writes_order_cancelled(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'customer_email' => 'stock@example.com',
            'customer_name' => 'Stock User',
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        $this->deliverStock('stock.reservation_failed', [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'reason' => 'Not enough stock',
        ]);

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertSame('insufficient_stock', $fresh->cancel_reason);
        $event = OutboxQueue::first('order.cancelled');
        $this->assertNotNull($event);
        $this->assertSame($order->id, $event->payload['order_id']);
        $this->assertSame('stock@example.com', $event->payload['email']);
        $this->assertSame('insufficient_stock', $event->payload['reason']);
    }

    public function test_stock_reserved_replay_with_same_saga_id_is_idempotent(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        $payload = [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'status' => 'success',
        ];

        $this->deliverStock('stock.reserved', $payload);
        $this->assertSame('awaiting_payment', $order->fresh()->status->value);

        Order::query()->whereKey($order->id)->update(['status' => 'pending']);

        $this->deliverStock('stock.reserved', $payload);

        $this->assertSame('pending', $order->fresh()->status->value);
    }
}
