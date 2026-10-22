<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\ProductSnapshot;
use App\Services\SagaStepGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\UsesFakeStripe;
use Tests\Concerns\UsesRedis;
use Tests\TestCase;

/**
 * Webhook compensate / confirm paths with locally signed payloads (no Stripe HTTP).
 */
class PaymentServiceWebhookUnitTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;
    use UsesRedis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableRedis();
        $this->useFakeStripe();
    }

    public function test_checkout_completed_writes_paid_and_confirmed_outbox(): void
    {
        $order = $this->createOrder('awaiting_payment');
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount' => '50.00',
            'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_test_unit_paid',
        ]);

        $signed = $this->signFakeStripeWebhook([
            'id' => 'evt_unit_paid',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_unit_paid',
                    'payment_intent' => 'pi_unit_paid',
                    'payment_status' => 'paid',
                    'amount_total' => 5000,
                    'currency' => 'rub',
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'payment_id' => (string) $payment->id,
                    ],
                ],
            ],
        ]);

        $this->call(
            'POST',
            '/api/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => $signed['header'],
            ],
            $signed['payload']
        )->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);

        $paid = OutboxEvent::query()->where('event_type', 'order.paid')->first();
        $confirmed = OutboxEvent::query()->where('event_type', 'order.confirmed')->first();
        $commit = OutboxEvent::query()->where('event_type', 'stock.commit_requested')->first();
        $this->assertNotNull($paid);
        $this->assertNotNull($confirmed);
        $this->assertNotNull($commit);
        $this->assertSame('paid@example.com', $paid->payload['email'] ?? null);
        $this->assertSame('confirmed', $paid->payload['status'] ?? null);
        $this->assertSame('order.paid', $paid->payload['event_type'] ?? null);
        $this->assertSame('order.confirmed', $confirmed->payload['event_type'] ?? null);
        $this->assertSame($order->saga_id, $commit->payload['saga_id'] ?? null);
        $this->assertSame(
            SagaStepGuard::STATUS_DONE,
            app(SagaStepGuard::class)->get($order->saga_id, 'stock.commit_requested')['status'] ?? null
        );
    }

    public function test_checkout_expired_requests_stock_release(): void
    {
        $order = $this->createOrder('awaiting_payment');
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount' => '50.00',
            'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_test_unit_expired',
        ]);

        $signed = $this->signFakeStripeWebhook([
            'id' => 'evt_unit_expired',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_test_unit_expired',
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'payment_id' => (string) $payment->id,
                    ],
                ],
            ],
        ]);

        $this->call(
            'POST',
            '/api/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => $signed['header'],
            ],
            $signed['payload']
        )->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'payment_failed']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'failed']);

        $release = OutboxEvent::query()->where('event_type', 'stock.release_requested')->first();
        $this->assertNotNull($release);
        $this->assertSame($order->saga_id, $release->payload['saga_id'] ?? null);
        $this->assertSame([
            ['product_id' => 42, 'store_id' => 3, 'quantity' => 1],
        ], $release->payload['items'] ?? null);

        $this->assertSame(
            'done',
            app(SagaStepGuard::class)->get($order->saga_id, 'stock.release_requested')['status'] ?? null
        );
    }

    private function createOrder(string $status): Order
    {
        ProductSnapshot::query()->create([
            'id' => 42,
            'name' => 'Item',
            'price' => '50.00',
            'store_id' => 3,
        ]);

        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 7,
            'customer_email' => 'paid@example.com',
            'customer_name' => 'Paid User',
            'status' => $status,
            'total_amount' => '50.00',
        ]);
        $order->items()->create([
            'product_id' => 42,
            'quantity' => 1,
            'unit_price' => '50.00',
        ]);

        return $order->fresh('items.productSnapshot');
    }
}
