<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Console\Commands\KafkaOutboxWorker;
use App\Kafka\Handlers\StockEventsHandler;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Services\SagaStepGuard;
use Illuminate\Support\Str;
use Junges\Kafka\Facades\Kafka;

final class SagaInsufficientStockFlowTest extends IntegrationTestCase
{
    public function test_reservation_failed_cancels_order_and_publishes_cancelled(): void
    {
        $this->fakeKafka();
        $this->seedSnapshot(502, 'Scarce', '15.00', 4);

        $create = $this->postJson(
            '/api/orders',
            $this->orderCreatePayload(
                userId: 11,
                email: 'scarce@example.com',
                name: 'Scarce Buyer',
                items: [['product_id' => 502, 'quantity' => 50]],
            ),
            ['Idempotency-Key' => 'it-scarce-'.Str::uuid()]
        );
        $create->assertCreated()->assertJsonPath('status', 'pending');
        $orderId = (int) $create->json('id');
        $sagaId = Order::query()->findOrFail($orderId)->saga_id;

        resolve(KafkaOutboxWorker::class)->processBatch();

        // Direct handler invoke (Kafka consume is flaky with live brokers/workers).
        resolve(StockEventsHandler::class)($this->stubConsumerMessage('stock.reservation_failed', [
            'saga_id' => $sagaId,
            'order_id' => $orderId,
            'reason' => 'Not enough stock',
        ]));

        $this->assertSame('cancelled', Order::query()->findOrFail($orderId)->status->value);
        $this->assertSame(
            SagaStepGuard::STATUS_FAILED,
            resolve(SagaStepGuard::class)->get($sagaId, 'stock.reservation_failed')['status'] ?? null
        );

        $cancelled = OutboxEvent::query()->where('event_type', 'order.cancelled')->first();
        $this->assertNotNull($cancelled);
        $this->assertSame('insufficient_stock', $cancelled->payload['reason'] ?? null);
        $this->assertSame('scarce@example.com', $cancelled->payload['email'] ?? null);

        resolve(KafkaOutboxWorker::class)->processBatch();

        Kafka::assertPublishedOn('order.events', null, function ($message) use ($orderId): bool {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'order.cancelled'
                && is_array($body)
                && (int) ($body['order_id'] ?? 0) === $orderId
                && ($body['reason'] ?? null) === 'insufficient_stock';
        });

        $this->postJson('/api/orders/'.$orderId.'/pay', ['user_id' => 11], [
            'Idempotency-Key' => 'it-scarce-pay-'.Str::uuid(),
        ])->assertUnprocessable();
    }
}
