<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\CatalogCacheKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {}

    // GET /api/admin/products
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $query = Product::with(['category', 'store']);

        $query->when($request->filled('store_id'), function ($q) use ($request) {
            $q->where('store_id', (int) $request->input('store_id'));
        });

        return response()->json($query->paginate($perPage));
    }

    // POST /api/admin/products
    public function store(StoreProductRequest $request): JsonResponse
    {
        // Сервис создаст товар и отправит событие в Kafka
        $product = $this->productService->createProduct($request->validated());

        return response()->json(['message' => 'Товар добавлен', 'product' => $product], 201);
    }

    public function show($id)
    {   // Когда память редиса заполнится, он будет убирать самые редко вызываемые
        $product = Cache::tags(['products'])->remember(CatalogCacheKeys::product($id), 86400, function () use ($id) {
            return Product::with(['category', 'store', 'attributes'])->findOrFail($id)->toArray();
        });

        return response()->json(['product' => $product]);
    }

    // PUT/PATCH /api/admin/products/{product}
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {

        // Сервис обновит товар и отправит событие в Kafka
        $product = $this->productService->updateProduct($product, $request->validated());

        return response()->json(['message' => 'Товар обновлен', 'product' => $product]);
    }

    // DELETE /api/admin/products/{product}
    public function destroy(Product $product): JsonResponse
    {
        // Сервис удалит товар и отправит старые данные в Kafka
        $this->productService->deleteProduct($product);

        return response()->json(['message' => 'Товар удален']);
    }
}
