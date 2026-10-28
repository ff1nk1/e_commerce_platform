<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AdminListOrdersRequest;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

final class AdminOrderController extends Controller
{
    public function __construct(
        private OrderService $orders
    ) {}

    public function index(AdminListOrdersRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->orders->listAll((int) ($validated['per_page'] ?? 15));

        return response()->json($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $order = $this->orders->findAny($id);

        return response()->json($this->orders->serializeOrder($order));
    }

    public function ship(int $id): JsonResponse
    {
        $order = $this->orders->shipForAdmin($id);

        return response()->json($this->orders->serializeOrder($order));
    }
}
