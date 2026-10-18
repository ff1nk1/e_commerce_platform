<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Domain\Money;
use App\Domain\OrderStatus;
use App\Events\OrderConfirmed;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OrderPayloadFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfirmOrderFromPayment
{
    public function __construct(
        private OrderPayloadFactory $payloads,
        private PaymentGateway $stripe,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public function fromCheckoutSession(array $session): void
    {
        $payment = $this->findPaymentForSession($session);
        if ($payment === null) {
            return;
        }

        if (($session['payment_status'] ?? null) !== 'paid') {
            Log::info('Stripe checkout completed without paid status; skipping confirm', [
                'payment_id' => $payment->id,
                'payment_status' => $session['payment_status'] ?? null,
            ]);

            return;
        }

        $expectedMinor = Money::of((string) $payment->amount)->toMinorUnits();
        $actualMinor = isset($session['amount_total']) ? (int) $session['amount_total'] : null;
        $currency = strtolower((string) ($session['currency'] ?? ''));

        if ($actualMinor === null || $actualMinor !== $expectedMinor || $currency !== 'rub') {
            Log::error('Stripe checkout amount/currency mismatch; skipping confirm', [
                'payment_id' => $payment->id,
                'expected_minor' => $expectedMinor,
                'actual_minor' => $actualMinor,
                'currency' => $currency,
            ]);

            return;
        }

        DB::transaction(function () use ($payment, $session) {
            $payment = Payment::query()->lockForUpdate()->find($payment->id);
            if ($payment === null || $payment->status === 'succeeded') {
                return;
            }

            $order = Order::query()->lockForUpdate()->find($payment->order_id);
            if ($order === null) {
                return;
            }

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
        });
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function refundOrphanCharge(Payment $payment, array $session): void
    {
        $intentId = $payment->stripe_payment_intent_id
            ?? (is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null);

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

    /**
     * @param  array<string, mixed>  $session
     */
    private function findPaymentForSession(array $session): ?Payment
    {
        $sessionId = $session['id'] ?? null;
        $paymentId = $session['metadata']['payment_id'] ?? null;

        if (is_numeric($paymentId)) {
            $payment = Payment::query()->find((int) $paymentId);
            if ($payment !== null) {
                return $payment;
            }
        }

        if (is_string($sessionId) && $sessionId !== '') {
            return Payment::query()
                ->where('stripe_checkout_session_id', $sessionId)
                ->first();
        }

        return null;
    }
}
