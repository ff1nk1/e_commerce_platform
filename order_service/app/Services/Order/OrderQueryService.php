<?php

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrderQueryService
{
    public function __construct(
        private OrderPayloadFactory $payloads,
    ) {}

    public function listForUser(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Order::query()
            ->with('items')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function listAll(int $perPage = 15): LengthAwarePaginator
    {
        return Order::query()
            ->with('items')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findForUser(int $orderId, int $userId): Order
    {
        $order = Order::query()
            ->with(['items.productSnapshot', 'payments'])
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException('Order not found.');
        }

        return $order;
    }

    public function findAny(int $orderId): Order
    {
        $order = Order::query()
            ->with(['items.productSnapshot', 'payments'])
            ->find($orderId);

        if ($order === null) {
            throw new NotFoundHttpException('Order not found.');
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Order $order): array
    {
        return $this->payloads->serializeOrder($order);
    }
}
