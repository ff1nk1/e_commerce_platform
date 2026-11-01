<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilterRequest;
use App\Services\Categories\ListCategoriesService;
use App\Services\Products\ListProductsService;
use Illuminate\Http\JsonResponse;

final class CatalogController extends Controller
{
    public function __construct(
        private ListCategoriesService $listCategories,
        private ListProductsService $listProducts,
    ) {}

    // GET /api/catalog/categories
    public function show_categories(): JsonResponse
    {
        return response()->json(
            $this->listCategories->handle()
        );
    }

    // GET /api/catalog/categories/{cat_slug}/products
    public function show_products_by_cat_id(CatalogFilterRequest $request, string $cat_slug): JsonResponse
    {
        return response()->json(
            $this->listProducts->byCategorySlug($cat_slug, $request->filters())
        );
    }

    // GET /api/catalog/stores/{store_id}/products
    public function show_products_by_store_id(CatalogFilterRequest $request, int $store_id): JsonResponse
    {
        return response()->json(
            $this->listProducts->byStoreId($store_id, $request->filters())
        );
    }
}
