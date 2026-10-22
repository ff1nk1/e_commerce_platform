<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCatalogController extends Controller
{
    public function __construct(
        private CatalogService $catalogService
    ) {}

    // GET /api/admin/categories
    public function index(Request $request): JsonResponse
    {
        $minPerPage = 1;
        $maxPerPage = 100;
        $defaultPerPage = 20;
        $perPage = max($minPerPage, min($maxPerPage, (int) $request->input('per_page', $defaultPerPage)));

        return response()->json(
            Category::with('parent')->paginate($perPage)
        );
    }

    // POST /api/admin/categories
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        // $request->validated() вернет только те данные, которые прошли проверку в StoreCategoryRequest
        $category = $this->catalogService->createCategory($request->validated());

        return response()->json(['message' => 'Категория создана', 'category' => $category], 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category = $this->catalogService->updateCategory($category, $request->validated());

        return response()->json(['message' => 'Категория обновлена', 'category' => $category]);
    }

    // GET /api/admin/categories/{category}
    public function show(Category $category): JsonResponse
    {
        return response()->json(['category' => $category->load('parent', 'children')]);
    }

    // DELETE /api/admin/categories/{category}
    public function destroy(Category $category): JsonResponse
    {
        $this->catalogService->deleteCategory($category);

        return response()->json(['message' => 'Категория удалена']);
    }
}
