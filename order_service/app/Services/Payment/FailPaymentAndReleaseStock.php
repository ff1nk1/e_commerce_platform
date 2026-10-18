<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\RequestStockRelease;
use Illuminate\Support\Facades\DB;

class FailPaymentAndReleaseStock
{
    public function __construct(
        private RequestStockRelease $stockRelease,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public function fromCheckoutSession(array $session): void
    {
        $payment = $this->findPaymentForSession($session);
        if ($payment === null || $payment->status !== 'pending') {
            return;
        }

        $releaseOrder = null;

        DB::transaction(function () use ($payment, &$releaseOrder) {
            $payment = Payment::query()->lockForUpdate()->find($payment->id);
            if ($payment === null || $payment->status !== 'pending') {
                return;
            }

            $payment->status = 'failed';
            $payment->save();

            $order = Order::query()->lockForUpdate()->find($payment->order_id);
            if ($order === null || ! $order->statusEnum()->canFailPayment()) {
                return;
            }

            $order->failPayment();
            $order->save();

            $this->stockRelease->dispatch($order);
            $releaseOrder = $order;
        });

        if ($releaseOrder !== null) {
            $this->stockRelease->recordSagaStep($releaseOrder);
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
