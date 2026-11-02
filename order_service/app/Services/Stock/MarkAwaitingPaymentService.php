<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Models\Order;

final class MarkAwaitingPaymentService
{
    /**
     * Move a reserved order to awaiting_payment once stock is confirmed.
     */
    public function handle(Order $order): void
    {
        if (! $order->statusEnum()->canMarkAwaitingPayment()) {
            return;
        }

        $order->markAwaitingPayment();
        $order->save();
    }
}
