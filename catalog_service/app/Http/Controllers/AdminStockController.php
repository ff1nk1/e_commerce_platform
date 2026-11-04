<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\Stock\StockData;
use App\Http\Requests\StoreStockRequest;
use App\Http\Requests\UpdateStockRequest;
use App\Http\Resources\StockResource;
use App\Models\Stock;
use App\Services\Stock\CreateStockService;
use App\Services\Stock\UpdateStockQuantityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminStockController extends Controller
{
    public function __construct(
        private CreateStockService $createStock,
        private UpdateStockQuantityService $updateStockQuantity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $minPerPage = 1;
        $maxPerPage = 100;
        $defaultPerPage = 20;
        $perPage = max($minPerPage, min($maxPerPage, (int) $request->input('per_page', $defaultPerPage)));

        $query = Stock::with(['product', 'store']);

        $query->when($request->filled('store_id'), function ($q) use ($request): void {
            $q->where('store_id', (int) $request->input('store_id'));
        });

        $query->when($request->filled('product_id'), function ($q) use ($request): void {
            $q->where('product_id', (int) $request->input('product_id'));
        });

        $paginator = $query->orderBy('id')->paginate($perPage);

        return response()->json(
            $paginator->through(static fn (Stock $stock): array => StockResource::make($stock)->resolve())
        );
    }

    public function show(Stock $stock): JsonResponse
    {
        return response()->json(StockResource::make($stock->load(['product', 'store'])));
    }

    public function store(StoreStockRequest $request): JsonResponse
    {
        $stock = $this->createStock->handle(StockData::fromArray($request->validated()));

        return response()->json([
            'message' => 'Stock created',
            'stock' => StockResource::make($stock),
        ], 201);
    }

    public function update(UpdateStockRequest $request, Stock $stock): JsonResponse
    {
        $stock = $this->updateStockQuantity->handle($stock, (int) $request->validated()['quantity']);

        return response()->json([
            'message' => 'Stock updated',
            'stock' => StockResource::make($stock),
        ]);
    }
}
