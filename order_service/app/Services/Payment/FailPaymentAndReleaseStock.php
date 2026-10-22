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
        $releaseOrder = null;

        DB::transaction(function () use ($session, &$releaseOrder) {
            $payment = $this->lockPaymentForSession($session);
            if ($payment === null || $payment->status !== 'pending') {
                return;
            }

            $payment->status = 'failed';
            $payment->save();

            $order = Order::query()->lockForUpdate()->find($payment->order_id);
            if ($order === null || ! $order->statusEnum()->canFailPayment()) {
                return;
            }

            $this->applyFail($order, $releaseOrder);
        });

        if ($releaseOrder !== null) {
            $this->stockRelease->recordSagaStep($releaseOrder);
        }
    }

    private function applyFail(Order $order, ?Order &$releaseOrder): void
    {
        $order->failPayment();
        $order->save();

        $this->stockRelease->dispatch($order);
        $releaseOrder = $order;
    }

    /**
     * Single locked Payment load for this checkout session (no prior unlocked find).
     *
     * @param  array<string, mixed>  $session
     */
    private function lockPaymentForSession(array $session): ?Payment
    {
        $sessionId = $session['id'] ?? null;
        $paymentId = $session['metadata']['payment_id'] ?? null;

        if (is_numeric($paymentId)) {
            $payment = Payment::query()->lockForUpdate()->find((int) $paymentId);
            if ($payment !== null) {
                return $payment;
            }
        }

        if (is_string($sessionId) && $sessionId !== '') {
            return Payment::query()
                ->where('stripe_checkout_session_id', $sessionId)
                ->lockForUpdate()
                ->first();
        }

        return null;
    }
}
