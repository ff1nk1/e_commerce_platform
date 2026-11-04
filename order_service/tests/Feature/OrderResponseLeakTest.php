<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public order responses must not leak internal orchestration identifiers
 * (`saga_id`) or Stripe identifiers.
 */
final class OrderResponseLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_does_not_leak_saga_or_stripe_ids(): void
    {
        $order = $this->createOrderWithPayment();

        $response = $this->getJson('/api/orders/'.$order->id.'?user_id=7')->assertOk();

        $response->assertJsonMissingPath('saga_id');
        $response->assertJsonMissingPath('payments.0.stripe_checkout_session_id');
        $response->assertJsonMissingPath('payments.0.stripe_payment_intent_id');
        $response->assertJsonPath('id', $order->id);
        $response->assertJsonPath('status', 'awaiting_payment');
    }

    public function test_index_does_not_leak_saga_or_stripe_ids(): void
    {
        $this->createOrderWithPayment();

        $response = $this->getJson('/api/orders?user_id=7')->assertOk();

        $response->assertJsonMissingPath('data.0.saga_id');
        $response->assertJsonMissingPath('data.0.payments.0.stripe_checkout_session_id');
        $response->assertJsonPath('data.0.status', 'awaiting_payment');
    }

    public function test_admin_show_does_not_leak_saga_or_stripe_ids(): void
    {
        $order = $this->createOrderWithPayment();

        $response = $this->getJson('/api/admin/orders/'.$order->id)->assertOk();

        $response->assertJsonMissingPath('saga_id');
        $response->assertJsonMissingPath('payments.0.stripe_checkout_session_id');
    }

    private function createOrderWithPayment(): Order
    {
        ProductSnapshot::query()->create([
            'id' => 42,
            'name' => 'Phone',
            'price' => '199.99',
            'store_id' => 1,
        ]);

        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 7,
            'customer_email' => 'leak@example.com',
            'customer_name' => 'Leak User',
            'status' => OrderStatus::AwaitingPayment,
            'total_amount' => '199.99',
        ]);

        $order->items()->create([
            'product_id' => 42,
            'quantity' => 1,
            'unit_price' => '199.99',
        ]);

        Payment::query()->create([
            'order_id' => $order->id,
            'amount' => '199.99',
            'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_secret',
            'stripe_payment_intent_id' => 'pi_secret',
        ]);

        return $order;
    }
}
