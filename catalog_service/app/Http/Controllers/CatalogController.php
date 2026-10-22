<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilterRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\CatalogService;
use App\Support\CatalogCacheKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CatalogController extends Controller
{
    public function __construct(
        private CatalogService $catalogService
    ) {}

    // GET /api/catalog/categories
    public function show_categories(): JsonResponse
    {
        return response()->json(
            $this->catalogService->getAllCategories()
        );
    }

    // GET /api/catalog/categories/{cat_slug}/products
    public function show_products_by_cat_id(CatalogFilterRequest $request, string $cat_slug): JsonResponse
    {
        $cacheKey = CatalogCacheKeys::categoryProducts($cat_slug, $request->validated());
        $categoryProductsTtl = now()->addHours(2);
        $perPage = 20;

        $data = Cache::tags(['products', 'categories'])->remember($cacheKey, $categoryProductsTtl, function () use ($request, $cat_slug, $perPage) {
            // Поиск категории тоже прячем в кэш, чтобы сэкономить 1 запрос к БД
            $category = Category::where('slug', $cat_slug)->firstOrFail();

            $allIds = $category->getTreeIds();
            $category->unsetRelation('allChildren');

            $query = Product::with(['store', 'category'])
                ->whereIn('category_id', $allIds);

            $products = $this->applyFilters($query, $request)->paginate($perPage);

            // Возвращаем массив с готовыми данными
            return [
                'category' => $category->toArray(),
                'products' => $products->toArray(),
            ];
        });

        // Отдаем данные в виде JSON
        return response()->json($data);
    }

    // GET /api/catalog/stores/{store_id}/products
    public function show_products_by_store_id(CatalogFilterRequest $request, int $store_id): JsonResponse
    {
        $store = Store::findOrFail($store_id);

        $query = Product::with(['store', 'category'])
            ->where('store_id', $store_id);

        $perPage = 20;
        $products = $this->applyFilters($query, $request, $store_id)->paginate($perPage);

        return response()->json([
            'store' => $store,
            'products' => $products,
        ]);
    }

    /**
     * Приватный метод для фильтрации товаров на витрине
     */
    private function applyFilters(Builder $query, CatalogFilterRequest $request, ?int $storeId = null): Builder
    {
        $filters = $request->validated();

        $query->when($filters['search'] ?? null, function (Builder $q, $search) {
            $q->where('name', 'like', "%{$search}%");
        });

        $query->when($filters['min_price'] ?? null, function (Builder $q, $minPrice) {
            $q->where('price', '>=', $minPrice);
        });

        $query->when($filters['max_price'] ?? null, function (Builder $q, $maxPrice) {
            $q->where('price', '<=', $maxPrice);
        });

        if (($filters['status'] ?? null) === 'in_stock') {
            // Sellable qty: physical quantity minus reserved holds.
            $availableStockSql = '(stocks.quantity - stocks.reserved) > 0';
            $query->whereExists(function ($sub) use ($storeId, $availableStockSql) {
                $sub->selectRaw('1')
                    ->from('stocks')
                    ->whereColumn('stocks.product_id', 'products.id')
                    ->whereRaw($availableStockSql);

                if ($storeId !== null) {
                    $sub->where('stocks.store_id', $storeId);
                } else {
                    $sub->whereColumn('stocks.store_id', 'products.store_id');
                }
            });
        }

        $sort = $filters['sort'] ?? 'new';

        match ($sort) {
            'price_asc' => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        return $query;
    }
}
