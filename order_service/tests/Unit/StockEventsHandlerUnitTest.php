<?php

namespace Tests\Unit;

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
class StockEventsHandlerUnitTest extends TestCase
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

        app(StockEventsHandler::class)($this->message('stock.reserved', [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'status' => 'success',
        ]));

        $this->assertSame('awaiting_payment', $order->fresh()->status->value);
        $this->assertSame(
            SagaStepGuard::STATUS_DONE,
            app(SagaStepGuard::class)->get($sagaId, 'stock.reserved')['status'] ?? null
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

        app(StockEventsHandler::class)($this->message('stock.reservation_failed', [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'reason' => 'Not enough stock',
        ]));

        $this->assertSame('cancelled', $order->fresh()->status->value);

        $event = OutboxEvent::query()->where('event_type', 'order.cancelled')->first();
        $this->assertNotNull($event);
        $this->assertSame('insufficient_stock', $event->payload['reason'] ?? null);
        $this->assertSame('stock@example.com', $event->payload['email'] ?? null);
        $this->assertSame(
            SagaStepGuard::STATUS_FAILED,
            app(SagaStepGuard::class)->get($sagaId, 'stock.reservation_failed')['status'] ?? null
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

        app(StockEventsHandler::class)($this->message('stock.reserved', $payload));
        $this->assertSame('awaiting_payment', $order->fresh()->status->value);

        Order::query()->whereKey($order->id)->update(['status' => 'pending']);

        app(StockEventsHandler::class)($this->message('stock.reserved', $payload));
        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_busy_lock_skips_handler_work(): void
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

        app(SagaStepGuard::class)->acquire($sagaId, 'stock.reserved');

        app(StockEventsHandler::class)($this->message('stock.reserved', [
            'saga_id' => $sagaId,
            'order_id' => $order->id,
            'status' => 'success',
        ]));

        $this->assertSame('pending', $order->fresh()->status->value);
        app(SagaStepGuard::class)->release($sagaId, 'stock.reserved');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function message(string $eventType, array $body): ConsumerMessage
    {
        return new class($eventType, $body) implements ConsumerMessage
        {
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

            public function getHeaders(): array
            {
                return ['event-type' => $this->eventType];
            }

            public function getBody(): array
            {
                return $this->body;
            }

            public function getKey(): ?string
            {
                return (string) ($this->body['order_id'] ?? null);
            }

            public function getOffset(): ?int
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
