<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Order;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FindOrderService
{
    public function handle(int $orderId): Order
    {
        $order = Order::query()
            ->with(['items.productSnapshot', 'payments'])
            ->find($orderId);

        throw_if($order === null, NotFoundHttpException::class, 'Order not found.');

        return $order;
    }
}
