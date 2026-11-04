<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTO\Catalog\ProductFilters;
use App\Enums\CatalogAvailabilityFilter;
use App\Enums\ProductSort;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StoreResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

final class ListProductsService
{
    private const CATEGORY_PRODUCTS_CACHE_TTL_SECONDS = 7200;

    /**
     * @return array{category: array<string, mixed>, products: array<string, mixed>}
     */
    public function byCategorySlug(string $catSlug, ProductFilters $filters): array
    {
        $cacheKey = ProductCache::categoryProducts($catSlug, $filters->toArray());

        /** @var array{category: array<string, mixed>, products: array<string, mixed>} */
        return Cache::tags(['products', 'categories'])->remember(
            $cacheKey,
            self::CATEGORY_PRODUCTS_CACHE_TTL_SECONDS,
            function () use ($catSlug, $filters): array {
                $category = Category::query()->where('slug', $catSlug)->firstOrFail();

                $allIds = $category->getTreeIds();
                $category->unsetRelation('allChildren');

                $query = Product::with(['store', 'category'])
                    ->whereIn('category_id', $allIds);

                $products = $this->applyFilters($query, $filters)->paginate(
                    perPage: $filters->perPage,
                    page: $filters->page,
                );

                return [
                    'category' => CategoryResource::make($category)->resolve(),
                    'products' => $this->presentPaginator($products),
                ];
            }
        );
    }

    /**
     * @return array{store: array<string, mixed>, products: array<string, mixed>}
     */
    public function byStoreId(int $storeId, ProductFilters $filters): array
    {
        $store = Store::query()->findOrFail($storeId);

        $query = Product::with(['store', 'category'])
            ->where('store_id', $storeId);

        $products = $this->applyFilters($query, $filters, $storeId)->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );

        return [
            'store' => StoreResource::make($store)->resolve(),
            'products' => $this->presentPaginator($products),
        ];
    }

    /**
     * Keep the Laravel paginator envelope (`current_page`, `last_page`, …)
     * while whitelisting each item through {@see ProductResource}.
     *
     * @param  LengthAwarePaginator<int, Product>  $paginator
     * @return array<string, mixed>
     */
    private function presentPaginator(LengthAwarePaginator $paginator): array
    {
        return $paginator
            ->through(static fn (Product $product): array => ProductResource::make($product)->resolve())
            ->toArray();
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applyFilters(Builder $query, ProductFilters $filters, ?int $storeId = null): Builder
    {
        $query->when($filters->search, function (Builder $q, string $search): void {
            $q->where('name', 'like', "%{$search}%");
        });

        $query->when($filters->minPrice, function (Builder $q, float $minPrice): void {
            $q->where('price', '>=', $minPrice);
        });

        $query->when($filters->maxPrice, function (Builder $q, float $maxPrice): void {
            $q->where('price', '<=', $maxPrice);
        });

        if ($filters->status === CatalogAvailabilityFilter::InStock) {
            $query->whereExists(function ($sub) use ($storeId): void {
                $sub->selectRaw('1')
                    ->from('stocks')
                    ->whereColumn('stocks.product_id', 'products.id')
                    ->whereRaw('(stocks.quantity - stocks.reserved) > 0');

                if ($storeId !== null) {
                    $sub->where('stocks.store_id', $storeId);
                } else {
                    $sub->whereColumn('stocks.store_id', 'products.store_id');
                }
            });
        }

        match ($filters->sort) {
            ProductSort::PriceAsc => $query->orderBy('price', 'asc'),
            ProductSort::PriceDesc => $query->orderBy('price', 'desc'),
            ProductSort::New => $query->latest(),
        };

        return $query;
    }
}
