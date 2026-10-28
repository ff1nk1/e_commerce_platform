<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\SagaStepBusyException;
use App\Kafka\Handlers\StockEventsHandler;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\ProductSnapshot;
use App\Services\SagaStepGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Junges\Kafka\Contracts\ConsumerMessage;
use Tests\Concerns\UsesRedis;
use Tests\TestCase;

/**
 * Handler step logic with real Redis saga guard + sqlite DB (phpunit.xml).
 * ConsumerMessage is a thin stub — not a mock of Redis/Kafka/Stripe.
 */
final class StockEventsHandlerUnitTest extends TestCase
{
    use RefreshDatabase;
    use UsesRedis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableRedis();
    }

    public function test_stock_reserved_moves_pending_to_awaiting_payment(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'customer_email' => 'a@example.com',
            'customer_name' => 'A',
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        resolve(StockEventsHandler::class)($this->message('stock.reserved', [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'status' => 'success',
        ]));

        $this->assertSame('awaiting_payment', $order->fresh()->status->value);
        $this->assertSame(
            SagaStepGuard::STATUS_DONE,
            resolve(SagaStepGuard::class)->get($sagaId, 'stock.reserved')['status'] ?? null
        );
    }

    public function test_reservation_failed_cancels_and_writes_insufficient_stock(): void
    {
        ProductSnapshot::query()->create([
            'id' => 77,
            'name' => 'X',
            'price' => '10.00',
            'store_id' => 1,
        ]);

        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'customer_email' => 'stock@example.com',
            'customer_name' => 'Stock',
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);
        $order->items()->create([
            'product_id' => 77,
            'quantity' => 1,
            'unit_price' => '10.00',
        ]);

        resolve(StockEventsHandler::class)($this->message('stock.reservation_failed', [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'reason' => 'Not enough stock',
        ]));

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertSame('insufficient_stock', $fresh->cancel_reason);

        $event = OutboxEvent::query()->where('event_type', 'order.cancelled')->first();
        $this->assertNotNull($event);
        $this->assertSame('insufficient_stock', $event->payload['reason'] ?? null);
        $this->assertSame('stock@example.com', $event->payload['email'] ?? null);
        $this->assertSame(
            SagaStepGuard::STATUS_FAILED,
            resolve(SagaStepGuard::class)->get($sagaId, 'stock.reservation_failed')['status'] ?? null
        );
    }

    public function test_replay_of_stock_reserved_is_skipped(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'customer_email' => 'a@example.com',
            'customer_name' => 'A',
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        $payload = [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'status' => 'success',
        ];

        resolve(StockEventsHandler::class)($this->message('stock.reserved', $payload));
        $this->assertSame('awaiting_payment', $order->fresh()->status->value);

        Order::query()->whereKey($order->id)->update(['status' => 'pending']);

        resolve(StockEventsHandler::class)($this->message('stock.reserved', $payload));
        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_busy_lock_throws_so_message_is_retried(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'customer_email' => 'a@example.com',
            'customer_name' => 'A',
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        resolve(SagaStepGuard::class)->acquire($sagaId, 'stock.reserved');

        try {
            resolve(StockEventsHandler::class)($this->message('stock.reserved', [
                'saga_id' => $sagaId,
                'order_id' => $order->id,
                'status' => 'success',
            ]));
            $this->fail('Expected SagaStepBusyException');
        } catch (SagaStepBusyException) {
            $this->assertSame('pending', $order->fresh()->status->value);
        } finally {
            resolve(SagaStepGuard::class)->release($sagaId, 'stock.reserved');
        }
    }

    public function test_late_stock_reserved_on_cancelled_requests_release(): void
    {
        ProductSnapshot::query()->create([
            'id' => 88,
            'name' => 'Late',
            'price' => '10.00',
            'store_id' => 2,
        ]);

        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'customer_email' => 'late@example.com',
            'customer_name' => 'Late',
            'status' => 'cancelled',
            'total_amount' => '10.00',
        ]);
        $order->items()->create([
            'product_id' => 88,
            'quantity' => 2,
            'unit_price' => '10.00',
        ]);

        resolve(StockEventsHandler::class)($this->message('stock.reserved', [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'status' => 'success',
        ]));

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $release = OutboxEvent::query()->where('event_type', 'stock.release_requested')->first();
        $this->assertNotNull($release);
        $this->assertSame($sagaId, $release->payload['saga_id'] ?? null);
        $this->assertSame([
            ['product_id' => 88, 'store_id' => 2, 'quantity' => 2],
        ], $release->payload['items'] ?? null);
        $this->assertSame(
            SagaStepGuard::STATUS_DONE,
            resolve(SagaStepGuard::class)->get($sagaId, 'stock.reserved')['status'] ?? null
        );
        $this->assertSame(
            SagaStepGuard::STATUS_DONE,
            resolve(SagaStepGuard::class)->get($sagaId, 'stock.release_requested')['status'] ?? null
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function message(string $eventType, array $body): ConsumerMessage
    {
        return new class($eventType, $body) implements ConsumerMessage
        {
            /**
             * @param  array<string, mixed>  $body
             */
            public function __construct(
                private string $eventType,
                private array $body,
            ) {}

            public function getTopicName(): string
            {
                return 'stock.events';
            }

            public function getPartition(): int
            {
                return 0;
            }

            /**
             * @return array<string, mixed>
             */
            public function getHeaders(): array
            {
                return ['event-type' => $this->eventType];
            }

            /**
             * @return array<string, mixed>
             */
            public function getBody(): array
            {
                return $this->body;
            }

            public function getKey(): string
            {
                return (string) ($this->body['order_id'] ?? null);
            }

            public function getOffset(): int
            {
                return 0;
            }

            public function getTimestamp(): ?int
            {
                return null;
            }

            public function getMessageIdentifier(): string
            {
                return 'stub-'.($this->body['order_id'] ?? '0');
            }
        };
    }
}
