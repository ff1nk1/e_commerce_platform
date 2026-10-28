<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\OrderStatus;
use App\Models\Order;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BindsFakePaymentGateway;
use Tests\Concerns\BindsInMemoryIdempotency;
use Tests\TestCase;

final class PaymentCheckoutTest extends TestCase
{
    use BindsFakePaymentGateway;
    use BindsInMemoryIdempotency;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInMemoryIdempotency();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pay_creates_payment_and_returns_checkout_url(): void
    {
        $order = $this->createAwaitingPaymentOrder();

        $stripe = $this->mockPaymentGateway();
        $stripe->shouldReceive('createCheckoutSession')
            ->once()
            ->andReturn([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
                'payment_intent' => 'pi_test_123',
                'status' => 'open',
            ]);

        $this->postJson('/api/orders/'.$order->id.'/pay', ['user_id' => 7], [
            'Idempotency-Key' => 'pay-key-1',
        ])
            ->assertOk()
            ->assertJson(['checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_123']);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'stripe_checkout_session_id' => 'cs_test_123',
        ]);
    }

    public function test_pay_is_idempotent_for_same_key(): void
    {
        $order = $this->createAwaitingPaymentOrder();

        $stripe = $this->mockPaymentGateway();
        $stripe->shouldReceive('createCheckoutSession')
            ->once()
            ->andReturn([
                'id' => 'cs_test_idem',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_idem',
                'status' => 'open',
            ]);

        $headers = ['Idempotency-Key' => 'pay-dup-1'];
        $first = $this->postJson('/api/orders/'.$order->id.'/pay', ['user_id' => 7], $headers);
        $second = $this->postJson('/api/orders/'.$order->id.'/pay', ['user_id' => 7], $headers);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('checkout_url'), $second->json('checkout_url'));
        $this->assertSame($first->json('payment_id'), $second->json('payment_id'));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_pay_requires_idempotency_key(): void
    {
        $order = $this->createAwaitingPaymentOrder();

        $this->postJson('/api/orders/'.$order->id.'/pay', ['user_id' => 7])->assertUnprocessable();
    }

    public function test_pay_rejects_pending_before_stock_reserved(): void
    {
        $order = $this->createAwaitingPaymentOrder();
        $order->status = OrderStatus::Pending;
        $order->save();

        $stripe = $this->mockPaymentGateway();
        $stripe->shouldNotReceive('createCheckoutSession');

        $this->postJson('/api/orders/'.$order->id.'/pay', ['user_id' => 7], [
            'Idempotency-Key' => 'pay-pending',
        ])->assertUnprocessable();
    }

    private function createAwaitingPaymentOrder(): Order
    {
        ProductSnapshot::query()->create([
            'id' => 42,
            'name' => 'Phone',
            'price' => '199.99',
            'store_id' => 1,
        ]);

        $order = Order::query()->create([
            'user_id' => 7,
            'status' => OrderStatus::AwaitingPayment,
            'total_amount' => '199.99',
        ]);

        $order->items()->create([
            'product_id' => 42,
            'quantity' => 1,
            'unit_price' => '199.99',
        ]);

        return $order->fresh('items');
    }
}
