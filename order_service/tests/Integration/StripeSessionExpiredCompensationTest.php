<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Console\Commands\KafkaOutboxWorker;
use App\Kafka\Handlers\StockEventsHandler;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Services\SagaStepGuard;
use App\Services\Stripe\StripeClient;
use Illuminate\Support\Str;
use Junges\Kafka\Facades\Kafka;
use Tests\Concerns\RequiresStripe;

final class StripeSessionExpiredCompensationTest extends IntegrationTestCase
{
    use RequiresStripe;

    public function test_expired_checkout_session_webhook_releases_stock(): void
    {
        $this->requireStripeSecret();
        $this->requireStripeWebhookSecret();
        $this->fakeKafka();

        $this->seedSnapshot(505, 'Expire Item', '5000.00', 2);

        $create = $this->postJson(
            '/api/orders',
            $this->orderCreatePayload(
                userId: 21,
                email: 'expire@example.com',
                name: 'Expire Buyer',
                items: [['product_id' => 505, 'quantity' => 1]],
            ),
            ['Idempotency-Key' => 'it-expire-'.Str::uuid()]
        );
        $create->assertCreated();
        $orderId = (int) $create->json('id');
        $sagaId = Order::query()->findOrFail($orderId)->saga_id;

        resolve(KafkaOutboxWorker::class)->processBatch();

        resolve(StockEventsHandler::class)($this->stubConsumerMessage('stock.reserved', [
            'saga_id' => $sagaId,
            'order_id' => $orderId,
            'status' => 'success',
        ]));

        $pay = $this->postJson('/api/orders/'.$orderId.'/pay', ['user_id' => 21], [
            'Idempotency-Key' => 'it-expire-pay-'.Str::uuid(),
        ]);
        $pay->assertOk();
        $paymentId = (int) $pay->json('payment_id');
        $payment = Payment::query()->findOrFail($paymentId);

        resolve(StripeClient::class)->retrieveCheckoutSession($payment->stripe_checkout_session_id);

        $signed = $this->signStripeWebhookPayload([
            'id' => 'evt_expire_'.Str::uuid(),
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => $payment->stripe_checkout_session_id,
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

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'payment_failed']);
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'failed']);

        $this->assertSame(
            SagaStepGuard::STATUS_DONE,
            resolve(SagaStepGuard::class)->get($sagaId, 'stock.release_requested')['status'] ?? null
        );

        $release = OutboxEvent::query()->where('event_type', 'stock.release_requested')->first();
        $this->assertNotNull($release);
        $this->assertSame($sagaId, $release->payload['saga_id'] ?? null);
        $this->assertSame([
            ['product_id' => 505, 'store_id' => 2, 'quantity' => 1],
        ], $release->payload['items'] ?? null);

        resolve(KafkaOutboxWorker::class)->processBatch();

        Kafka::assertPublishedOn('order.events', null, function ($message) use ($orderId): bool {
            return ($message->getHeaders()['event-type'] ?? null) === 'stock.release_requested'
                && (int) ($message->getBody()['order_id'] ?? 0) === $orderId;
        });
    }
}
