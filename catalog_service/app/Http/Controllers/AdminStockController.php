<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockRequest;
use App\Http\Requests\UpdateStockRequest;
use App\Models\Stock;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStockController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $minPerPage = 1;
        $maxPerPage = 100;
        $defaultPerPage = 20;
        $perPage = max($minPerPage, min($maxPerPage, (int) $request->input('per_page', $defaultPerPage)));

        $query = Stock::with(['product', 'store']);

        $query->when($request->filled('store_id'), function ($q) use ($request) {
            $q->where('store_id', (int) $request->input('store_id'));
        });

        $query->when($request->filled('product_id'), function ($q) use ($request) {
            $q->where('product_id', (int) $request->input('product_id'));
        });

        return response()->json(
            $query->orderBy('id')->paginate($perPage)
        );
    }

    public function show(Stock $stock): JsonResponse
    {
        return response()->json($stock->load(['product', 'store']));
    }

    public function store(StoreStockRequest $request): JsonResponse
    {
        $stock = $this->stockService->createStock($request->validated());

        return response()->json([
            'message' => 'Stock created',
            'stock' => $stock,
        ], 201);
    }

    public function update(UpdateStockRequest $request, Stock $stock): JsonResponse
    {
        $stock = $this->stockService->updateQuantity($stock, (int) $request->validated()['quantity']);

        return response()->json([
            'message' => 'Stock updated',
            'stock' => $stock,
        ]);
    }
}
