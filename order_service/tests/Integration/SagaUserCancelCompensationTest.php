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

final class SagaUserCancelCompensationTest extends IntegrationTestCase
{
    public function test_cancel_after_stock_reserved_writes_cancelled_and_release(): void
    {
        $this->fakeKafka();
        $this->seedSnapshot(503, 'Cancelable', '30.00', 1);

        $create = $this->postJson(
            '/api/orders',
            $this->orderCreatePayload(
                userId: 8,
                email: 'cancel@example.com',
                name: 'Cancel Buyer',
                items: [['product_id' => 503, 'quantity' => 1]],
            ),
            ['Idempotency-Key' => 'it-cancel-'.Str::uuid()]
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

        $this->assertSame('awaiting_payment', Order::query()->findOrFail($orderId)->status->value);

        $this->postJson('/api/orders/'.$orderId.'/cancel', ['user_id' => 8])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(
            SagaStepGuard::STATUS_DONE,
            resolve(SagaStepGuard::class)->get($sagaId, 'stock.release_requested')['status'] ?? null
        );

        $cancelled = OutboxEvent::query()->where('event_type', 'order.cancelled')->first();
        $release = OutboxEvent::query()->where('event_type', 'stock.release_requested')->first();
        $this->assertNotNull($cancelled);
        $this->assertNotNull($release);
        $this->assertSame('user_cancelled', $cancelled->payload['reason'] ?? null);
        $this->assertSame('cancel@example.com', $cancelled->payload['email'] ?? null);
        $this->assertSame($sagaId, $release->payload['saga_id'] ?? null);

        resolve(KafkaOutboxWorker::class)->processBatch();

        Kafka::assertPublishedOn('order.events', null, function ($message) use ($orderId): bool {
            return ($message->getHeaders()['event-type'] ?? null) === 'order.cancelled'
                && (int) ($message->getBody()['order_id'] ?? 0) === $orderId;
        });
        Kafka::assertPublishedOn('order.events', null, function ($message) use ($orderId): bool {
            return ($message->getHeaders()['event-type'] ?? null) === 'stock.release_requested'
                && (int) ($message->getBody()['order_id'] ?? 0) === $orderId;
        });
    }
}
