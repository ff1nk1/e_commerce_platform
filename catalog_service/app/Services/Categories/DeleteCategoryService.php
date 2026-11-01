<?php

declare(strict_types=1);

namespace App\Services\Categories;

use App\DTO\Catalog\CategoryData;
use App\Events\CategoryDeleted;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class DeleteCategoryService
{
    public function __construct(
        private CategoriesCache $cache,
        private UpdateCategoryService $updates,
    ) {}

    public function handle(Category $category): bool
    {
        // 1. Явно делаем детей корневыми и отправляем события в Kafka
        foreach ($category->children as $child) {
            $this->updates->handle($child, CategoryData::fromArray(['parent_id' => null]));
            // UpdateCategoryService сам отправит CategoryUpdated
        }

        // 2. Удаляем самого родителя
        $result = DB::transaction(function () use ($category): bool {
            $categoryData = clone $category;
            $deleted = (bool) $category->delete();
            event(new CategoryDeleted($categoryData));

            return $deleted;
        });

        $this->cache->clear();

        return $result;
    }
}
