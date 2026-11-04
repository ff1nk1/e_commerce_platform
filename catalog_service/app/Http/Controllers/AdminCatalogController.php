<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTO\Catalog\CategoryData;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Categories\CreateCategoryService;
use App\Services\Categories\DeleteCategoryService;
use App\Services\Categories\UpdateCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminCatalogController extends Controller
{
    public function __construct(
        private CreateCategoryService $createCategory,
        private UpdateCategoryService $updateCategory,
        private DeleteCategoryService $deleteCategory,
    ) {}

    // GET /api/admin/categories
    public function index(Request $request): JsonResponse
    {
        $minPerPage = 1;
        $maxPerPage = 100;
        $defaultPerPage = 20;
        $perPage = max($minPerPage, min($maxPerPage, (int) $request->input('per_page', $defaultPerPage)));

        $paginator = Category::with('parent')->paginate($perPage);

        return response()->json(
            $paginator->through(static fn (Category $category): array => CategoryResource::make($category)->resolve())
        );
    }

    // POST /api/admin/categories
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        // $request->validated() вернет только те данные, которые прошли проверку в StoreCategoryRequest
        $category = $this->createCategory->handle(CategoryData::fromArray($request->validated()));

        return response()->json(['message' => 'Категория создана', 'category' => CategoryResource::make($category)], 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category = $this->updateCategory->handle($category, CategoryData::fromArray($request->validated()));

        return response()->json(['message' => 'Категория обновлена', 'category' => CategoryResource::make($category)]);
    }

    // GET /api/admin/categories/{category}
    public function show(Category $category): JsonResponse
    {
        return response()->json(['category' => CategoryResource::make($category->load('parent', 'children'))]);
    }

    // DELETE /api/admin/categories/{category}
    public function destroy(Category $category): JsonResponse
    {
        $this->deleteCategory->handle($category);

        return response()->json(['message' => 'Категория удалена']);
    }
}
