<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\DTO\Orders\OrderNotification;
use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Events\OrderCancelled;
use App\Models\Order;

final class CancelUnreservedOrderService
{
    /**
     * Cancel an order whose stock reservation failed (insufficient stock).
     */
    public function handle(Order $order): void
    {
        if ($order->statusEnum() !== OrderStatus::Pending) {
            return;
        }

        $order->cancel();
        $order->cancel_reason = CancelReason::InsufficientStock->value;
        $order->save();

        event(new OrderCancelled(
            OrderNotification::fromOrder($order, CancelReason::InsufficientStock)
        ));
    }
}
