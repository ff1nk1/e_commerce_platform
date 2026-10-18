<?php

namespace Tests\Feature;

use App\Kafka\Handlers\StockEventsHandler;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Junges\Kafka\Contracts\ConsumerMessage;
use Mockery;
use Tests\Concerns\BindsFakePaymentGateway;
use Tests\Concerns\BindsInMemoryIdempotency;
use Tests\Concerns\BindsInMemorySagaStepGuard;
use Tests\TestCase;

/**
 * Simulates: order created → catalog cannot reserve → stock.reservation_failed → order cancelled.
 * Pay must stay blocked; no Stripe redirect path.
 */
class InsufficientStockSagaTest extends TestCase
{
    use BindsFakePaymentGateway;
    use BindsInMemoryIdempotency;
    use BindsInMemorySagaStepGuard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInMemoryIdempotency();
        $this->bindInMemorySagaStepGuard();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_order_is_cancelled_when_stock_reservation_fails_and_pay_is_blocked(): void
    {
        ProductSnapshot::query()->create([
            'id' => 99,
            'name' => 'Scarce item',
            'price' => '25.00',
            'store_id' => 4,
        ]);

        $create = $this->postJson('/api/orders', [
            'user_id' => 11,
            'email' => 'buyer11@example.com',
            'name' => 'Buyer Eleven',
            'items' => [
                ['product_id' => 99, 'quantity' => 100],
            ],
        ], [
            'Idempotency-Key' => 'scarce-order-1',
        ]);

        $create->assertCreated()->assertJsonPath('status', 'pending');
        $orderId = (int) $create->json('id');
        $sagaId = (string) Order::query()->findOrFail($orderId)->saga_id;

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'order.created',
        ]);

        app(StockEventsHandler::class)($this->stockMessage('stock.reservation_failed', [
            'saga_id' => $sagaId,
            'order_id' => $orderId,
            'reason' => 'Not enough stock',
        ]));

        $show = $this->getJson('/api/orders/'.$orderId.'?user_id=11');
        $show->assertOk()->assertJsonPath('status', 'cancelled');

        $cancelled = OutboxEvent::query()->where('event_type', 'order.cancelled')->first();
        $this->assertNotNull($cancelled);
        $this->assertSame($orderId, $cancelled->payload['order_id']);
        $this->assertSame('buyer11@example.com', $cancelled->payload['email']);
        $this->assertSame('insufficient_stock', $cancelled->payload['reason']);

        $this->assertSame(
            0,
            OutboxEvent::query()->where('event_type', 'stock.release_requested')->count()
        );

        $stripe = $this->mockPaymentGateway();
        $stripe->shouldNotReceive('createCheckoutSession');

        $this->postJson('/api/orders/'.$orderId.'/pay', [
            'user_id' => 11,
        ], [
            'Idempotency-Key' => 'scarce-pay-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_stock_reserved_then_pay_allowed(): void
    {
        ProductSnapshot::query()->create([
            'id' => 98,
            'name' => 'In stock',
            'price' => '10.00',
            'store_id' => 1,
        ]);

        $create = $this->postJson('/api/orders', [
            'user_id' => 11,
            'email' => 'buyer11@example.com',
            'name' => 'Buyer Eleven',
            'items' => [
                ['product_id' => 98, 'quantity' => 1],
            ],
        ], [
            'Idempotency-Key' => 'ok-order-1',
        ]);
        $orderId = (int) $create->json('id');
        $sagaId = (string) Order::query()->findOrFail($orderId)->saga_id;

        app(StockEventsHandler::class)($this->stockMessage('stock.reserved', [
            'saga_id' => $sagaId,
            'order_id' => $orderId,
            'status' => 'success',
        ]));

        $this->assertSame('awaiting_payment', $this->getJson('/api/orders/'.$orderId.'?user_id=11')->json('status'));

        $stripe = $this->mockPaymentGateway();
        $stripe->shouldReceive('createCheckoutSession')->once()->andReturn([
            'id' => 'cs_ok',
            'url' => 'https://checkout.stripe.com/c/pay/cs_ok',
            'status' => 'open',
        ]);

        $this->postJson('/api/orders/'.$orderId.'/pay', [
            'user_id' => 11,
        ], [
            'Idempotency-Key' => 'ok-pay-1',
        ])->assertOk()->assertJsonPath('checkout_url', 'https://checkout.stripe.com/c/pay/cs_ok');
    }

    private function stockMessage(string $eventType, array $body): ConsumerMessage
    {
        $message = Mockery::mock(ConsumerMessage::class);
        $message->shouldReceive('getHeaders')->andReturn(['event-type' => $eventType]);
        $message->shouldReceive('getBody')->andReturn($body);

        return $message;
    }
}
