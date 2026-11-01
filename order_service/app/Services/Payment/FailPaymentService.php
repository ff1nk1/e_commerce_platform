<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTO\Payments\StripeCheckoutSession;
use App\Enums\PaymentStatus;
use App\Infrastructure\Stripe\StripePaymentResolver;
use App\Messaging\FulfillmentEvents;
use App\Messaging\PaymentFailed;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

final class FailPaymentService
{
    public function __construct(
        private FulfillmentEvents $fulfillment,
    ) {}

    public function handle(StripeCheckoutSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $payment = StripePaymentResolver::lockForSession($session);
            if (! $payment instanceof Payment || $payment->status !== PaymentStatus::Pending->value) {
                return;
            }

            $payment->status = PaymentStatus::Failed->value;
            $payment->save();

            $order = Order::query()->lockForUpdate()->find($payment->order_id);
            if ($order === null || ! $order->statusEnum()->canFailPayment()) {
                return;
            }

            $this->applyFail($order);
        });
    }

    private function applyFail(Order $order): void
    {
        $order->failPayment();
        $order->save();

        $sagaId = is_string($order->saga_id) ? $order->saga_id : '';
        $this->fulfillment->publish(new PaymentFailed($sagaId), $sagaId);
    }
}
