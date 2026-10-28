<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\RequestStockRelease;
use App\Services\Stripe\StripePaymentResolver;
use Illuminate\Support\Facades\DB;

final class FailPaymentAndReleaseStock
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

        DB::transaction(function () use ($session, &$releaseOrder): void {
            $payment = StripePaymentResolver::lockForSession($session);
            if (! $payment instanceof Payment || $payment->status !== 'pending') {
                return;
            }

            $payment->status = 'failed';
            $payment->save();

            $order = Order::query()->lockForUpdate()->find($payment->order_id);
            if ($order === null || ! $order->statusEnum()->canFailPayment()) {
                return;
            }

            $releaseOrder = $this->applyFail($order);
        });

        if ($releaseOrder instanceof Order) {
            $this->stockRelease->recordSagaStep($releaseOrder);
        }
    }

    private function applyFail(Order $order): Order
    {
        $order->failPayment();
        $order->save();

        $this->stockRelease->dispatch($order);

        return $order;
    }
}
