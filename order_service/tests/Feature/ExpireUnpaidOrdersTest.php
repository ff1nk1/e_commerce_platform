<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class ExpireUnpaidOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_old_awaiting_payment_and_pending_orders(): void
    {
        ProductSnapshot::query()->create([
            'id' => 5,
            'name' => 'X',
            'price' => '1.00',
            'store_id' => 9,
        ]);

        $awaiting = $this->order('awaiting_payment', 'awaiting@example.com', 16);
        $pending = $this->order('pending', 'pending@example.com', 20);
        $fresh = $this->order('awaiting_payment', 'fresh@example.com', 5);

        $command = $this->artisan('orders:expire-unpaid');
        throw_unless($command instanceof PendingCommand, \RuntimeException::class, 'Artisan did not return a pending command.');
        $command->assertSuccessful();
        $command->run();

        $expiredAwaiting = $this->reloaded($awaiting);
        $expiredPending = $this->reloaded($pending);
        $stillOpen = $this->reloaded($fresh);
        $this->assertSame('cancelled', $expiredAwaiting->status->value);
        $this->assertSame('payment_timeout', $expiredAwaiting->cancel_reason);
        $this->assertSame('cancelled', $expiredPending->status->value);
        $this->assertSame('payment_timeout', $expiredPending->cancel_reason);
        $this->assertSame('awaiting_payment', $stillOpen->status->value);

        $cancelled = OutboxQueue::all('kafka-outbox');
        $reasons = [];
        $releases = 0;
        foreach ($cancelled as $message) {
            if ($message->event_type === 'order.cancelled') {
                $reasons[] = $message->payload['reason'] ?? null;
            }
            if ($message->event_type === 'stock.release_requested') {
                $releases++;
            }
        }

        $this->assertSame(['payment_timeout', 'payment_timeout'], $reasons);
        $this->assertSame(2, $releases);
    }

    private function order(string $status, string $email, int $ageMinutes): Order
    {
        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 2,
            'customer_email' => $email,
            'customer_name' => 'Buyer',
            'status' => $status,
            'total_amount' => '1.00',
        ]);
        $order->items()->create([
            'product_id' => 5,
            'quantity' => 1,
            'unit_price' => '1.00',
        ]);
        $order->created_at = now()->subMinutes($ageMinutes);
        $order->save();

        return $order;
    }

    private function reloaded(Order $order): Order
    {
        $fresh = $order->fresh();
        throw_unless($fresh instanceof Order, \RuntimeException::class, 'Order missing after reload.');

        return $fresh;
    }
}
