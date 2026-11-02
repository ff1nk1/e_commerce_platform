<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FindUserOrderService
{
    public function handle(int $orderId, int $userId): Order
    {
        $order = Order::query()
            ->with(['items.productSnapshot', 'payments'])
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->first();

        throw_if($order === null, NotFoundHttpException::class, 'Order not found.');

        return $order;
    }
}
