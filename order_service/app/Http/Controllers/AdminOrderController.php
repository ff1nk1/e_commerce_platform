<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\Orders\OrderView;
use App\Http\Requests\AdminListOrdersRequest;
use App\Services\Orders\FindOrderService;
use App\Services\Orders\ListAllOrdersService;
use App\Services\Orders\ShipOrderService;
use Illuminate\Http\JsonResponse;

final class AdminOrderController extends Controller
{
    public function __construct(
        private ListAllOrdersService $listAllOrders,
        private FindOrderService $findOrder,
        private ShipOrderService $shipOrder,
    ) {}

    public function index(AdminListOrdersRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->listAllOrders->handle((int) ($validated['per_page'] ?? 15));

        return response()->json($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $order = $this->findOrder->handle($id);

        return response()->json(OrderView::fromOrder($order)->toArray());
    }

    public function ship(int $id): JsonResponse
    {
        $order = $this->shipOrder->handle($id);

        return response()->json(OrderView::fromOrder($order)->toArray());
    }
}
