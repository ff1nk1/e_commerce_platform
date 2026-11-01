<?php

declare(strict_types=1);

namespace App\Services\Categories;

use App\DTO\Catalog\CategoryData;
use App\Events\CategoryUpdated;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class UpdateCategoryService
{
    public function __construct(
        private CategoriesCache $cache,
    ) {}

    public function handle(Category $category, CategoryData $data): Category
    {
        $category = DB::transaction(function () use ($category, $data): Category {
            $category->update($data->toArray());
            event(new CategoryUpdated($category));

            return $category;
        });

        $this->cache->clear();

        return $category;
    }
}
