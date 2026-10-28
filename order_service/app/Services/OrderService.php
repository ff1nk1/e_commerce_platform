<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Services\Order\CancelOrderService;
use App\Services\Order\CreateOrderService;
use App\Services\Order\OrderPayloadFactory;
use App\Services\Order\OrderQueryService;
use App\Services\Order\ShipOrderService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Facade over order use-cases for HTTP controllers and Kafka handlers.
 */
final class OrderService
{
    public function __construct(
        private CreateOrderService $create,
        private OrderQueryService $query,
        private CancelOrderService $cancel,
        private ShipOrderService $ship,
        private OrderPayloadFactory $payloads,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return array{status: int, body: array<string, mixed>}
     */
    public function create(int $userId, string $email, string $name, array $items, string $idempotencyKey): array
    {
        return $this->create->execute($userId, $email, $name, $items, $idempotencyKey);
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function listForUser(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query->listForUser($userId, $perPage);
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function listAll(int $perPage = 15): LengthAwarePaginator
    {
        return $this->query->listAll($perPage);
    }

    public function findForUser(int $orderId, int $userId): Order
    {
        return $this->query->findForUser($orderId, $userId);
    }

    public function findAny(int $orderId): Order
    {
        return $this->query->findAny($orderId);
    }

    public function cancelForUser(int $orderId, int $userId): Order
    {
        return $this->cancel->forUser($orderId, $userId);
    }

    public function shipForAdmin(int $orderId): Order
    {
        return $this->ship->forAdmin($orderId);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOrder(Order $order): array
    {
        return $this->query->serialize($order);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function notificationPayload(Order $order, array $extra = []): array
    {
        return $this->payloads->notificationPayload($order, $extra);
    }

    /**
     * @return list<array{product_id: int, store_id: int, quantity: int}>
     */
    public function itemsPayload(Order $order): array
    {
        return $this->payloads->itemsPayload($order);
    }
}
