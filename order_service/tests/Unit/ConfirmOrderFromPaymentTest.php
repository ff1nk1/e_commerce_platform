<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductSnapshot;
use App\Services\Payment\ConfirmOrderFromPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Shop\Messaging\OutboxQueue;
use Tests\Concerns\BindsFakePaymentGateway;
use Tests\TestCase;

final class ConfirmOrderFromPaymentTest extends TestCase
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

        resolve(ConfirmOrderFromPayment::class)->fromCheckoutSession([
            'id' => 'cs_pending',
            'payment_intent' => 'pi_1',
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'rub',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]);

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame(0, OutboxQueue::count('order.confirmed'));
    }

    public function test_rejects_amount_mismatch(): void
    {
        [$order, $payment] = $this->orderAndPayment('awaiting_payment');

        resolve(ConfirmOrderFromPayment::class)->fromCheckoutSession([
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

    public function test_queues_refund_when_order_already_cancelled(): void
    {
        [$order, $payment] = $this->orderAndPayment('cancelled');

        $stripe = $this->mockPaymentGateway();
        $stripe->shouldNotReceive('refundPaymentIntent');

        $this->deliverPaidSession($payment, 'cs_orphan', 'pi_orphan');

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame('refund_pending', $payment->fresh()->status);
        $this->assertSame('pi_orphan', $this->reloadedPayment($payment)->stripe_payment_intent_id);
        $this->assertSame(0, OutboxQueue::count('order.confirmed'));
        $this->assertSame(1, $this->inboxMessagesContaining('pi_orphan'));

        $this->deliverPaidSession($payment, 'cs_orphan', 'pi_orphan');
        $this->assertSame(1, $this->inboxMessagesContaining('pi_orphan'));
    }

    public function test_queues_refund_when_order_already_confirmed(): void
    {
        [$order, $payment] = $this->orderAndPayment('confirmed');

        $stripe = $this->mockPaymentGateway();
        $stripe->shouldNotReceive('refundPaymentIntent');

        $this->deliverPaidSession($payment, 'cs_second', 'pi_second');

        $reloadedOrder = $this->reloadedOrder($order);
        $reloadedPayment = $this->reloadedPayment($payment);
        $this->assertSame('confirmed', $reloadedOrder->status->value);
        $this->assertSame('refund_pending', $reloadedPayment->status);
        $this->assertSame(0, OutboxQueue::count('order.confirmed'));
        $this->assertSame(1, $this->inboxMessagesContaining('pi_second'));
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

    private function reloadedOrder(Order $order): Order
    {
        $fresh = $order->fresh();
        throw_unless($fresh instanceof Order, \RuntimeException::class, 'Order missing after reload.');

        return $fresh;
    }

    private function reloadedPayment(Payment $payment): Payment
    {
        $fresh = $payment->fresh();
        throw_unless($fresh instanceof Payment, \RuntimeException::class, 'Payment missing after reload.');

        return $fresh;
    }

    private function deliverPaidSession(Payment $payment, string $sessionId, string $paymentIntentId): void
    {
        resolve(ConfirmOrderFromPayment::class)->fromCheckoutSession([
            'id' => $sessionId,
            'payment_intent' => $paymentIntentId,
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'rub',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]);
    }

    private function inboxMessagesContaining(string $needle): int
    {
        if (! Schema::hasTable('enqueue')) {
            return 0;
        }

        return DB::table('enqueue')
            ->where('queue', 'inbox')
            ->get()
            ->filter(static fn (object $row): bool => str_contains((string) $row->body, $needle))
            ->count();
    }
}
