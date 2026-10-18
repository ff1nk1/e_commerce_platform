<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BindsInMemorySagaStepGuard;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use BindsInMemorySagaStepGuard;
    use RefreshDatabase;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInMemorySagaStepGuard();
        $this->useFakeStripe();
    }

    public function test_webhook_marks_confirmed_and_writes_order_paid(): void
    {
        $order = $this->createOrderWithItem('awaiting_payment');
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount' => '50.00',
            'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_test_abc',
        ]);

        $this->postSignedWebhook([
            'id' => 'evt_1',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_abc',
                    'payment_intent' => 'pi_abc',
                    'payment_status' => 'paid',
                    'amount_total' => 5000,
                    'currency' => 'rub',
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'payment_id' => (string) $payment->id,
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed']);

        $paid = OutboxEvent::query()->where('event_type', 'order.paid')->first();
        $confirmed = OutboxEvent::query()->where('event_type', 'order.confirmed')->first();
        $this->assertNotNull($paid);
        $this->assertNotNull($confirmed);
        $this->assertSame('paid@example.com', $paid->payload['email']);
        $this->assertSame('paid@example.com', $confirmed->payload['email']);
        $this->assertSame($order->id, $paid->payload['order_id']);
        $this->assertSame($order->id, $confirmed->payload['order_id']);
        $this->assertSame('confirmed', $paid->payload['status']);
        $this->assertSame('confirmed', $confirmed->payload['status']);
    }

    public function test_webhook_expired_marks_payment_failed_and_requests_release(): void
    {
        $order = $this->createOrderWithItem('awaiting_payment');
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount' => '50.00',
            'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_expired',
        ]);

        $this->postSignedWebhook([
            'id' => 'evt_2',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_expired',
                    'metadata' => ['payment_id' => (string) $payment->id],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'payment_failed']);
        $event = OutboxEvent::query()->where('event_type', 'stock.release_requested')->first();
        $this->assertNotNull($event);
        $this->assertSame($order->saga_id, $event->payload['saga_id']);
        $this->assertSame([
            ['product_id' => 42, 'store_id' => 3, 'quantity' => 1],
        ], $event->payload['items']);
        $this->assertArrayHasKey($order->saga_id.':stock.release_requested', $this->sagaStepStore);
    }

    private function createOrderWithItem(string $status): Order
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

    private function postSignedWebhook(array $event)
    {
        $signed = $this->signFakeStripeWebhook($event);

        return $this->call(
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
        );
    }
}
