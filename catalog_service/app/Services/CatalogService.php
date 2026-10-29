<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        $category = DB::transaction(function () use ($data): Category {
            $category = Category::query()->create($data);
            event(new CategoryCreated($category));

            return $category;
        });

        $this->clearCategoryCache();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data): Category
    {
        $category = DB::transaction(function () use ($category, $data): Category {
            $category->update($data);
            event(new CategoryUpdated($category));

            return $category;
        });

        $this->clearCategoryCache();

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
        $result = DB::transaction(function () use ($category): bool {
            $categoryData = clone $category;
            $deleted = (bool) $category->delete();
            event(new CategoryDeleted($categoryData));

            return $deleted;
        });

        $this->clearCategoryCache();

        return $result;
    }
}
