<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListOrdersRequest;
use App\Http\Requests\PayOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UserScopedOrderRequest;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

final class OrderController extends Controller
{
    public function __construct(
        private OrderService $orders,
        private PaymentService $payments,
    ) {}

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->orders->listForUser(
            (int) $validated['user_id'],
            (int) ($validated['per_page'] ?? 15)
        );

        return response()->json($paginator);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->orders->create(
            (int) $validated['user_id'],
            $validated['email'],
            $validated['name'],
            $validated['items'],
            $request->idempotencyKey()
        );

        return response()->json($result['body'], $result['status']);
    }

    public function show(UserScopedOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->orders->findForUser($id, (int) $request->validated()['user_id']);

        return response()->json($this->orders->serializeOrder($order));
    }

    public function cancel(UserScopedOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->orders->cancelForUser($id, (int) $request->validated()['user_id']);

        return response()->json($this->orders->serializeOrder($order));
    }

    public function pay(PayOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->orders->findForUser($id, (int) $request->validated()['user_id']);
        $result = $this->payments->createCheckout($order, $request->idempotencyKey());

        return response()->json($result);
    }
}
