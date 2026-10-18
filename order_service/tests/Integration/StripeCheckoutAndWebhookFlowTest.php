<?php

namespace Tests\Integration;

use App\Console\Commands\KafkaOutboxWorker;
use App\Kafka\Handlers\StockEventsHandler;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Services\Stripe\StripeClient;
use Illuminate\Support\Str;
use Junges\Kafka\Facades\Kafka;
use Tests\Concerns\RequiresStripe;

class StripeCheckoutAndWebhookFlowTest extends IntegrationTestCase
{
    use RequiresStripe;

    public function test_real_checkout_session_and_completed_webhook_confirms_order(): void
    {
        $this->requireStripeSecret();
        $this->requireStripeWebhookSecret();
        $this->fakeKafka();

        // Stripe PL accounts reject tiny RUB totals (< ~200 groszy after FX).
        $this->seedSnapshot(504, 'Paid Item', '5000.00', 1);

        $create = $this->postJson(
            '/api/orders',
            $this->orderCreatePayload(
                userId: 20,
                email: 'stripe-buyer@example.com',
                name: 'Stripe Buyer',
                items: [['product_id' => 504, 'quantity' => 1]],
            ),
            ['Idempotency-Key' => 'it-stripe-'.Str::uuid()]
        );
        $create->assertCreated();
        $orderId = (int) $create->json('id');
        $sagaId = Order::query()->findOrFail($orderId)->saga_id;

        app(KafkaOutboxWorker::class)->processBatch();

        app(StockEventsHandler::class)($this->stubConsumerMessage('stock.reserved', [
            'saga_id' => $sagaId,
            'order_id' => $orderId,
            'status' => 'success',
        ]));

        $pay = $this->postJson('/api/orders/'.$orderId.'/pay', ['user_id' => 20], [
            'Idempotency-Key' => 'it-stripe-pay-'.Str::uuid(),
        ]);
        $pay->assertOk();
        $this->assertNotEmpty($pay->json('checkout_url'));
        $paymentId = (int) $pay->json('payment_id');

        $payment = Payment::query()->findOrFail($paymentId);
        $this->assertNotEmpty($payment->stripe_checkout_session_id);

        $session = app(StripeClient::class)->retrieveCheckoutSession($payment->stripe_checkout_session_id);
        $this->assertSame($payment->stripe_checkout_session_id, $session['id'] ?? null);

        $signed = $this->signStripeWebhookPayload([
            'id' => 'evt_it_'.Str::uuid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $payment->stripe_checkout_session_id,
                    'payment_intent' => is_string($session['payment_intent'] ?? null)
                        ? $session['payment_intent']
                        : 'pi_it_'.Str::random(8),
                    'payment_status' => 'paid',
                    'amount_total' => 500000,
                    'currency' => 'rub',
                    'metadata' => [
                        'order_id' => (string) $orderId,
                        'payment_id' => (string) $paymentId,
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

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'succeeded']);

        $paid = OutboxEvent::query()->where('event_type', 'order.paid')->first();
        $confirmed = OutboxEvent::query()->where('event_type', 'order.confirmed')->first();
        $this->assertNotNull($paid);
        $this->assertNotNull($confirmed);
        $this->assertSame('stripe-buyer@example.com', $paid->payload['email'] ?? null);
        $this->assertSame('confirmed', $confirmed->payload['status'] ?? null);

        app(KafkaOutboxWorker::class)->processBatch();

        Kafka::assertPublishedOn('order.events', null, function ($message) use ($orderId) {
            return ($message->getHeaders()['event-type'] ?? null) === 'order.paid'
                && (int) ($message->getBody()['order_id'] ?? 0) === $orderId;
        });
        Kafka::assertPublishedOn('order.events', null, function ($message) use ($orderId) {
            return ($message->getHeaders()['event-type'] ?? null) === 'order.confirmed'
                && (int) ($message->getBody()['order_id'] ?? 0) === $orderId;
        });
    }
}
