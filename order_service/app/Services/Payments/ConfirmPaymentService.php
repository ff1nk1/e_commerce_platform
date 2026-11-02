<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\DTO\Orders\OrderNotification;
use App\DTO\Payments\StripeCheckoutSession;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderConfirmed;
use App\Events\OrderPaid;
use App\Infrastructure\Stripe\StripePaymentResolver;
use App\Infrastructure\Stripe\StripeSessionMoney;
use App\Messaging\FulfillmentEvents;
use App\Messaging\PaymentCaptured;
use App\Messaging\RefundOrphanPayment;
use App\Models\Order;
use App\Models\Payment;
use Ecotone\Modelling\EventBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// stripe listen --forward-to https://app.localhost/api/webhooks/stripe --skip-verify
final class ConfirmPaymentService
{
    public function __construct(
        private FulfillmentEvents $fulfillment,
        private EventBus $events,
    ) {}

    public function handle(StripeCheckoutSession $session): void
    {
        if ($session->paymentStatus !== 'paid') {
            Log::info('Stripe checkout completed without paid status; skipping confirm', [
                'payment_id' => $session->metadataPaymentId,
                'payment_status' => $session->paymentStatus,
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

    private function applyConfirm(Payment $payment, Order $order, StripeCheckoutSession $session): void
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

        $payment->status = PaymentStatus::Succeeded->value;
        if (is_string($session->paymentIntent)) {
            $payment->stripe_payment_intent_id = $session->paymentIntent;
        }
        $payment->save();

        $order->confirm();
        $order->save();

        $notification = OrderNotification::fromOrder($order);
        event(new OrderPaid($notification));
        event(new OrderConfirmed($notification));

        $sagaId = is_string($order->saga_id) ? $order->saga_id : '';
        $this->fulfillment->publish(new PaymentCaptured($sagaId), $sagaId);
    }

    private function paymentAlreadySettled(Payment $payment): bool
    {
        return in_array($payment->status, [
            PaymentStatus::Succeeded->value,
            PaymentStatus::RefundPending->value,
            PaymentStatus::Refunded->value,
        ], true);
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

    private function queueOrphanRefund(Payment $payment, Order $order, StripeCheckoutSession $session): void
    {
        $fromPayment = $payment->stripe_payment_intent_id;
        $fromSession = is_string($session->paymentIntent) ? $session->paymentIntent : null;
        $intentId = (is_string($fromPayment) && $fromPayment !== '') ? $fromPayment : $fromSession;

        if (! is_string($intentId) || $intentId === '') {
            Log::error('Orphan Stripe charge without payment_intent; manual refund required', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
            ]);

            return;
        }

        $payment->status = PaymentStatus::RefundPending->value;
        $payment->stripe_payment_intent_id = $intentId;
        $payment->save();

        $this->events->publish(new RefundOrphanPayment(
            (int) $payment->id,
            (int) $order->id,
            $intentId,
        ));
    }
}
