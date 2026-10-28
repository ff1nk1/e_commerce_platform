<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Domain\OrderStatus;
use App\Events\OrderConfirmed;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OrderPayloadFactory;
use App\Services\Order\RequestStockCommit;
use App\Services\Stripe\StripePaymentResolver;
use App\Services\Stripe\StripeSessionMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ConfirmOrderFromPayment
{
    public function __construct(
        private OrderPayloadFactory $payloads,
        private PaymentGateway $stripe,
        private RequestStockCommit $stockCommit,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public function fromCheckoutSession(array $session): void
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            $metadataPaymentId = $session['metadata']['payment_id'] ?? null;
            $paymentStatus = $session['payment_status'] ?? null;
            Log::info('Stripe checkout completed without paid status; skipping confirm', [
                'payment_id' => $metadataPaymentId,
                'payment_status' => $paymentStatus,
            ]);

            return;
        }

        $commitOrder = null;

        DB::transaction(function () use ($session, &$commitOrder): void {
            $payment = StripePaymentResolver::lockForSession($session);
            if (! $payment instanceof Payment || $payment->status === 'succeeded') {
                return;
            }

            if (! StripeSessionMoney::matchesExpected($payment, $session)) {
                Log::error('Stripe checkout amount/currency mismatch; skipping confirm', [
                    'payment_id' => $payment->id,
                    ...StripeSessionMoney::compareDetails($payment, $session),
                ]);

                return;
            }

            $order = Order::query()->lockForUpdate()->find($payment->order_id);
            if ($order === null) {
                return;
            }

            $this->applyConfirm($payment, $order, $session, $commitOrder);
        });

        if ($commitOrder instanceof Order) {
            $this->stockCommit->recordSagaStep($commitOrder);
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function applyConfirm(Payment $payment, Order $order, array $session, ?Order &$commitOrder): void
    {
        $status = $order->statusEnum();

        if ($status === OrderStatus::Confirmed) {
            $payment->status = 'succeeded';
            if (is_string($session['payment_intent'] ?? null)) {
                $payment->stripe_payment_intent_id = $session['payment_intent'];
            }
            $payment->save();

            return;
        }

        if (in_array($status, [OrderStatus::Cancelled, OrderStatus::PaymentFailed], true)) {
            $payment->status = 'succeeded';
            if (is_string($session['payment_intent'] ?? null)) {
                $payment->stripe_payment_intent_id = $session['payment_intent'];
            }
            $payment->save();

            $this->refundOrphanCharge($payment, $session);

            return;
        }

        if (! $status->canConfirmFromPayment()) {
            Log::warning('Stripe checkout paid but order not awaiting_payment; skipping confirm', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'status' => $status->value,
            ]);

            return;
        }

        $payment->status = 'succeeded';
        if (is_string($session['payment_intent'] ?? null)) {
            $payment->stripe_payment_intent_id = $session['payment_intent'];
        }
        $payment->save();

        $order->confirm();
        $order->save();

        $payload = $this->payloads->notificationPayload($order);
        event(new OrderPaid($payload));
        event(new OrderConfirmed($payload));

        $this->stockCommit->dispatch($order);
        $commitOrder = $order;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function refundOrphanCharge(Payment $payment, array $session): void
    {
        $fromPayment = $payment->stripe_payment_intent_id;
        $fromSession = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;
        $intentId = $fromPayment ?? $fromSession;

        if (! is_string($intentId) || $intentId === '') {
            Log::error('Orphan Stripe charge without payment_intent; manual refund required', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
            ]);

            return;
        }

        try {
            $this->stripe->refundPaymentIntent($intentId);
            Log::warning('Refunded orphan Stripe charge after cancel/payment_failed', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'payment_intent' => $intentId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to refund orphan Stripe charge', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'payment_intent' => $intentId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
