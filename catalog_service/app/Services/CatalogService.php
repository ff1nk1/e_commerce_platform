<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Models\Category;
use Illuminate\Support\Facades\Cache;

final class CatalogService
{
    private const ALL_CATEGORIES_CACHE_TTL_SECONDS = 3600;

    /**
     * Получение всех категорий из кэша.
     *
     * @return list<array<string, mixed>>
     */
    public function getAllCategories(): array
    {
        return Cache::tags(['categories'])->remember('all_categories', self::ALL_CATEGORIES_CACHE_TTL_SECONDS, function () {
            return Category::all()->toArray();
        });
    }

    /**
     * Очистка кэша при любых изменениях категорий.
     */
    public function clearCategoryCache(): void
    {
        Cache::tags(['categories'])->flush();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): Category
    {
        $category = Category::query()->create($data);

        $this->clearCategoryCache();
        event(new CategoryCreated($category));

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data): Category
    {
        $category->update($data);

        $this->clearCategoryCache();
        event(new CategoryUpdated($category));

        return $category;
    }

    public function deleteCategory(Category $category): bool
    {
        // 1. Явно делаем детей корневыми и отправляем события в Kafka
        foreach ($category->children as $child) {
            $this->updateCategory($child, ['parent_id' => null]);
            // updateCategory сам отправит CategoryUpdated
        }

        // 2. Удаляем самого родителя
        $categoryData = clone $category;
        $result = $category->delete();

        $this->clearCategoryCache();
        event(new CategoryDeleted($categoryData));

        return $result;
    }
}
