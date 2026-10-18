<?php

namespace App\Services\Order;

use App\Events\OrderShipped;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ShipOrderService
{
    public function __construct(
        private OrderPayloadFactory $payloads,
    ) {}

    public function forAdmin(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()
                ->with(['items.productSnapshot', 'payments'])
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new NotFoundHttpException('Order not found.');
            }

            $order->assertShippable();
            $order->ship();
            $order->save();

            event(new OrderShipped($this->payloads->notificationPayload($order)));

            return $order->load(['items.productSnapshot', 'payments']);
        });
    }
}
