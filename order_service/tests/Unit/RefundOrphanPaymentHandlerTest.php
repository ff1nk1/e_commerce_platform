<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Messaging\RefundOrphanPayment;
use App\Messaging\RefundOrphanPaymentHandler;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Stripe\StripeIdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\BindsFakePaymentGateway;
use Tests\TestCase;

final class RefundOrphanPaymentHandlerTest extends TestCase
{
    use BindsFakePaymentGateway;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_refunds_with_idempotency_key_and_marks_payment_refunded(): void
    {
        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 7,
            'customer_email' => 'paid@example.com',
            'customer_name' => 'Paid User',
            'status' => 'cancelled',
            'total_amount' => '50.00',
        ]);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount' => '50.00',
            'status' => 'refund_pending',
            'stripe_payment_intent_id' => 'pi_orphan',
        ]);

        $stripe = $this->mockPaymentGateway();
        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->with(
                'pi_orphan',
                null,
                StripeIdempotencyKey::forOrder($order->id, 'refund', 'pi_orphan'),
            )
            ->andReturn(['id' => 're_1']);

        resolve(RefundOrphanPaymentHandler::class)->onRefund(new RefundOrphanPayment(
            (int) $payment->id,
            (int) $order->id,
            'pi_orphan',
        ));

        $fresh = $payment->fresh();
        throw_unless($fresh instanceof Payment, \RuntimeException::class, 'Payment missing after reload.');
        $this->assertSame('refunded', $fresh->status);
    }
}
