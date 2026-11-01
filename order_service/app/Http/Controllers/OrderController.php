<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\Orders\OrderItemRequest;
use App\DTO\Orders\OrderView;
use App\Http\Requests\ListOrdersRequest;
use App\Http\Requests\PayOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UserScopedOrderRequest;
use App\Services\Order\CancelOrderService;
use App\Services\Order\CreateOrderService;
use App\Services\Order\FindUserOrderService;
use App\Services\Order\ListUserOrdersService;
use App\Services\Payment\CreateCheckoutService;
use Illuminate\Http\JsonResponse;

final class OrderController extends Controller
{
    public function __construct(
        private ListUserOrdersService $listUserOrders,
        private CreateOrderService $createOrder,
        private FindUserOrderService $findUserOrder,
        private CancelOrderService $cancelOrder,
        private CreateCheckoutService $createCheckout,
    ) {}

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->listUserOrders->handle(
            (int) $validated['user_id'],
            (int) ($validated['per_page'] ?? 15)
        );

        return response()->json($paginator);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->createOrder->handle(
            (int) $validated['user_id'],
            $validated['email'],
            $validated['name'],
            OrderItemRequest::fromArray($validated['items']),
            $request->idempotencyKey()
        );

        return response()->json($result->body, $result->status);
    }

    public function show(UserScopedOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->findUserOrder->handle($id, (int) $request->validated()['user_id']);

        return response()->json(OrderView::fromOrder($order)->toArray());
    }

    public function cancel(UserScopedOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->cancelOrder->handle($id, (int) $request->validated()['user_id']);

        return response()->json(OrderView::fromOrder($order)->toArray());
    }

    public function pay(PayOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->findUserOrder->handle($id, (int) $request->validated()['user_id']);
        $result = $this->createCheckout->handle($order, $request->idempotencyKey());

        return response()->json($result->toArray());
    }
}
