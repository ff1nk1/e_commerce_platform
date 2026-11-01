<?php

declare(strict_types=1);

namespace App\Services\Categories;

use App\DTO\Catalog\CategoryData;
use App\Events\CategoryCreated;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class CreateCategoryService
{
    public function __construct(
        private CategoriesCache $cache,
    ) {}

    public function handle(CategoryData $data): Category
    {
        $category = DB::transaction(function () use ($data): Category {
            $category = Category::query()->create($data->toArray());
            event(new CategoryCreated($category));

            return $category;
        });

        $this->cache->clear();

        return $category;
    }
}
