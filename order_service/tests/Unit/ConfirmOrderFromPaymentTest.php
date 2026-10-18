<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\ProductSnapshot;
use App\Services\Payment\ConfirmOrderFromPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\BindsFakePaymentGateway;
use Tests\TestCase;

class ConfirmOrderFromPaymentTest extends TestCase
{
    use BindsFakePaymentGateway;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_does_not_confirm_pending_order(): void
    {
        [$order, $payment] = $this->orderAndPayment('pending');

        app(ConfirmOrderFromPayment::class)->fromCheckoutSession([
            'id' => 'cs_pending',
            'payment_intent' => 'pi_1',
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'rub',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]);

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', 'order.confirmed')->count());
    }

    public function test_rejects_amount_mismatch(): void
    {
        [$order, $payment] = $this->orderAndPayment('awaiting_payment');

        app(ConfirmOrderFromPayment::class)->fromCheckoutSession([
            'id' => 'cs_bad_amount',
            'payment_intent' => 'pi_1',
            'payment_status' => 'paid',
            'amount_total' => 1,
            'currency' => 'rub',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]);

        $this->assertSame('awaiting_payment', $order->fresh()->status->value);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_refunds_when_order_already_cancelled(): void
    {
        [$order, $payment] = $this->orderAndPayment('cancelled');

        $stripe = $this->mockPaymentGateway();
        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->withArgs(fn ($intentId, $amount = null) => $intentId === 'pi_orphan')
            ->andReturn(['id' => 're_1']);

        app(ConfirmOrderFromPayment::class)->fromCheckoutSession([
            'id' => 'cs_orphan',
            'payment_intent' => 'pi_orphan',
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'rub',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]);

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', 'order.confirmed')->count());
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function orderAndPayment(string $status): array
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

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount' => '50.00',
            'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_'.$status,
        ]);

        return [$order, $payment];
    }
}
