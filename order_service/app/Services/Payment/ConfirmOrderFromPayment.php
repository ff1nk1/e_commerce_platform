<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Domain\OrderStatus;
use App\Events\OrderConfirmed;
use App\Events\OrderPaid;
use App\Messaging\FulfillmentEvents;
use App\Messaging\PaymentCaptured;
use App\Messaging\RefundOrphanPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OrderPayloadFactory;
use App\Services\Stripe\StripePaymentResolver;
use App\Services\Stripe\StripeSessionMoney;
use Ecotone\Modelling\EventBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ConfirmOrderFromPayment
{
    public function __construct(
        private OrderPayloadFactory $payloads,
        private FulfillmentEvents $fulfillment,
        private EventBus $events,
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

        DB::transaction(function () use ($session): void {
            $payment = StripePaymentResolver::lockForSession($session);
            if (! $payment instanceof Payment || $this->paymentAlreadySettled($payment)) {
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

            $this->applyConfirm($payment, $order, $session);
        });
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function applyConfirm(Payment $payment, Order $order, array $session): void
    {
        $status = $order->statusEnum();

        if ($this->shouldRefund($status)) {
            $this->queueOrphanRefund($payment, $order, $session);

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

        $sagaId = is_string($order->saga_id) ? $order->saga_id : '';
        $this->fulfillment->publish(new PaymentCaptured($sagaId), $sagaId);
    }

    private function paymentAlreadySettled(Payment $payment): bool
    {
        return in_array($payment->status, ['succeeded', 'refund_pending', 'refunded'], true);
    }

    private function shouldRefund(OrderStatus $status): bool
    {
        return in_array($status, [
            OrderStatus::Confirmed,
            OrderStatus::Cancelled,
            OrderStatus::PaymentFailed,
            OrderStatus::Shipped,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function queueOrphanRefund(Payment $payment, Order $order, array $session): void
    {
        $fromPayment = $payment->stripe_payment_intent_id;
        $fromSession = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;
        $intentId = (is_string($fromPayment) && $fromPayment !== '') ? $fromPayment : $fromSession;

        if (! is_string($intentId) || $intentId === '') {
            Log::error('Orphan Stripe charge without payment_intent; manual refund required', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
            ]);

            return;
        }

        $payment->status = 'refund_pending';
        $payment->stripe_payment_intent_id = $intentId;
        $payment->save();

        $this->events->publish(new RefundOrphanPayment(
            (int) $payment->id,
            (int) $order->id,
            $intentId,
        ));
    }
}
