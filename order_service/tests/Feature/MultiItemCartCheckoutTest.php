<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BindsFakePaymentGateway;
use Tests\Concerns\BindsInMemoryIdempotency;
use Tests\Concerns\BindsInMemorySagaStepGuard;
use Tests\TestCase;

final class MultiItemCartCheckoutTest extends TestCase
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

    public function test_multi_item_create_writes_outbox_and_pay_sends_all_line_items(): void
    {
        ProductSnapshot::query()->create([
            'id' => 10,
            'name' => 'Phone',
            'price' => '100.00',
            'store_id' => 1,
        ]);
        ProductSnapshot::query()->create([
            'id' => 20,
            'name' => 'Case',
            'price' => '25.50',
            'store_id' => 2,
        ]);

        $create = $this->postJson('/api/orders', [
            'user_id' => 7,
            'email' => 'cart@example.com',
            'name' => 'Cart User',
            'items' => [
                ['product_id' => 10, 'quantity' => 2],
                ['product_id' => 20, 'quantity' => 1],
            ],
        ], [
            'Idempotency-Key' => 'cart-checkout-1',
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('id');
        $this->assertSame('225.50', $create->json('total_amount'));

        $event = OutboxEvent::query()->where('event_type', 'order.created')->first();
        $this->assertNotNull($event);
        $this->assertCount(2, $event->payload['items']);

        $replay = $this->postJson('/api/orders', [
            'user_id' => 7,
            'email' => 'cart@example.com',
            'name' => 'Cart User',
            'items' => [
                ['product_id' => 10, 'quantity' => 2],
                ['product_id' => 20, 'quantity' => 1],
            ],
        ], [
            'Idempotency-Key' => 'cart-checkout-1',
        ]);
        $replay->assertCreated();
        $this->assertSame($orderId, $replay->json('id'));
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.created')->count());

        Order::query()->whereKey($orderId)->update(['status' => 'awaiting_payment']);

        $capturedLineItems = null;
        $stripe = $this->mockPaymentGateway();
        $stripe->shouldReceive('createCheckoutSession')
            ->once()
            ->andReturnUsing(function (array $params) use (&$capturedLineItems): array {
                $capturedLineItems = $params['line_items'] ?? null;

                return [
                    'id' => 'cs_cart_multi',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_cart_multi',
                    'payment_intent' => 'pi_cart_multi',
                    'status' => 'open',
                ];
            });

        $this->postJson('/api/orders/'.$orderId.'/pay', ['user_id' => 7], [
            'Idempotency-Key' => 'cart-pay-1',
        ])
            ->assertOk()
            ->assertJson(['checkout_url' => 'https://checkout.stripe.com/c/pay/cs_cart_multi']);

        $this->assertIsArray($capturedLineItems);
        $this->assertCount(2, $capturedLineItems);
        $this->assertSame(2, $capturedLineItems[0]['quantity']);
        $this->assertSame('Phone', $capturedLineItems[0]['price_data']['product_data']['name']);
        $this->assertSame(10000, $capturedLineItems[0]['price_data']['unit_amount']);
        $this->assertSame(1, $capturedLineItems[1]['quantity']);
        $this->assertSame('Case', $capturedLineItems[1]['price_data']['product_data']['name']);
        $this->assertSame(2550, $capturedLineItems[1]['price_data']['unit_amount']);
    }
}
