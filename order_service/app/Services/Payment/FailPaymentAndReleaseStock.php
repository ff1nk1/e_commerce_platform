<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Services\Order\RequestStockRelease;
use App\Services\Stripe\StripePaymentResolver;
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
            $payment = StripePaymentResolver::lockForSession($session);
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
}
