<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\Catalog\ProductData;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Products\CreateProductService;
use App\Services\Products\DeleteProductService;
use App\Services\Products\ProductCache;
use App\Services\Products\UpdateProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class ProductController extends Controller
{
    private const PRODUCT_CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private CreateProductService $createProduct,
        private UpdateProductService $updateProduct,
        private DeleteProductService $deleteProduct,
    ) {}

    // GET /api/admin/products
    public function index(Request $request): JsonResponse
    {
        $minPerPage = 1;
        $maxPerPage = 100;
        $defaultPerPage = 20;
        $perPage = max($minPerPage, min($maxPerPage, (int) $request->input('per_page', $defaultPerPage)));

        $query = Product::with(['category', 'store']);

        $query->when($request->filled('store_id'), function ($q) use ($request): void {
            $q->where('store_id', (int) $request->input('store_id'));
        });

        $paginator = $query->paginate($perPage);

        return response()->json(
            $paginator->through(static fn (Product $product): array => ProductResource::make($product)->resolve())
        );
    }

    // POST /api/admin/products
    public function store(StoreProductRequest $request): JsonResponse
    {
        // Сервис создаст товар и отправит событие в Kafka
        $product = $this->createProduct->handle(ProductData::fromArray($request->validated()));

        return response()->json(['message' => 'Товар добавлен', 'product' => ProductResource::make($product)], 201);
    }

    public function show(int|string $id): JsonResponse
    {   // Когда память редиса заполнится, он будет убирать самые редко вызываемые
        $product = Cache::tags(['products'])->remember(ProductCache::product($id), self::PRODUCT_CACHE_TTL_SECONDS, function () use ($id): array {
            $model = Product::with(['category', 'store', 'attributes'])->findOrFail($id);

            return ProductResource::make($model)->resolve();
        });

        return response()->json(['product' => $product]);
    }

    // PUT/PATCH /api/admin/products/{product}
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        // Сервис обновит товар и отправит событие в Kafka
        $product = $this->updateProduct->handle($product, ProductData::fromArray($request->validated()));

        return response()->json(['message' => 'Товар обновлен', 'product' => ProductResource::make($product)]);
    }

    // DELETE /api/admin/products/{product}
    public function destroy(Product $product): JsonResponse
    {
        // Сервис удалит товар и отправит старые данные в Kafka
        $this->deleteProduct->handle($product);

        return response()->json(['message' => 'Товар удален']);
    }
}
