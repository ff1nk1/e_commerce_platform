<?php

namespace Tests\Feature;

use App\Exceptions\SagaStepBusyException;
use App\Kafka\Handlers\StockEventsHandler;
use App\Models\Order;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Junges\Kafka\Contracts\ConsumerMessage;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\BindsInMemorySagaStepGuard;
use Tests\TestCase;

class StockEventsHandlerTest extends TestCase
{
    use BindsInMemorySagaStepGuard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInMemorySagaStepGuard();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_stock_reserved_moves_pending_order_to_awaiting_payment(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        app(StockEventsHandler::class)(
            $this->message('stock.reserved', [
                'saga_id' => $sagaId,
                'order_id' => $order->id,
                'status' => 'success',
            ])
        );

        $this->assertSame('awaiting_payment', $order->fresh()->status->value);
    }

    public function test_stock_reserved_without_saga_id_is_ignored(): void
    {
        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 1,
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        app(StockEventsHandler::class)(
            $this->message('stock.reserved', ['order_id' => $order->id, 'status' => 'success'])
        );

        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_stock_reserved_with_mismatched_saga_id_is_ignored(): void
    {
        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 1,
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        app(StockEventsHandler::class)(
            $this->message('stock.reserved', [
                'saga_id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'status' => 'success',
            ])
        );

        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_reservation_failed_cancels_order_and_writes_order_cancelled(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'customer_email' => 'stock@example.com',
            'customer_name' => 'Stock User',
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        app(StockEventsHandler::class)(
            $this->message('stock.reservation_failed', [
                'saga_id' => $sagaId,
                'order_id' => $order->id,
                'reason' => 'Not enough stock',
            ])
        );

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $event = OutboxEvent::query()->where('event_type', 'order.cancelled')->first();
        $this->assertNotNull($event);
        $this->assertSame($order->id, $event->payload['order_id']);
        $this->assertSame('stock@example.com', $event->payload['email']);
        $this->assertSame('insufficient_stock', $event->payload['reason']);
    }

    public function test_stock_reserved_replay_with_same_saga_id_is_idempotent(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
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
        $this->assertSame(
            'done',
            $this->sagaStepStore[$sagaId.':stock.reserved']['status'] ?? null
        );
    }

    public function test_stock_reserved_busy_lock_throws_so_message_is_retried(): void
    {
        $sagaId = (string) Str::uuid();
        $order = Order::query()->create([
            'saga_id' => $sagaId,
            'user_id' => 1,
            'status' => 'pending',
            'total_amount' => '10.00',
        ]);

        $this->sagaStepLocks[$sagaId.':stock.reserved'] = true;

        try {
            app(StockEventsHandler::class)(
                $this->message('stock.reserved', [
                    'saga_id' => $sagaId,
                    'order_id' => $order->id,
                    'status' => 'success',
                ])
            );
            $this->fail('Expected SagaStepBusyException');
        } catch (SagaStepBusyException) {
            $this->assertSame('pending', $order->fresh()->status->value);
            $this->assertArrayNotHasKey($sagaId.':stock.reserved', $this->sagaStepStore);
        }
    }

    private function message(string $eventType, array $body): ConsumerMessage&MockInterface
    {
        /** @var ConsumerMessage&MockInterface $message */
        $message = Mockery::mock(ConsumerMessage::class);
        $message->shouldReceive('getHeaders')->andReturn(['event-type' => $eventType]);
        $message->shouldReceive('getBody')->andReturn($body);

        return $message;
    }
}
