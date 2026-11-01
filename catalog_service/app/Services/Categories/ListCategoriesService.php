<?php

declare(strict_types=1);

namespace App\Services\Categories;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

final class ListCategoriesService
{
    private const ALL_CATEGORIES_CACHE_TTL_SECONDS = 3600;

    /**
     * Получение всех категорий из кэша.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(): array
    {
        return Cache::tags(['categories'])->remember('all_categories', self::ALL_CATEGORIES_CACHE_TTL_SECONDS, function () {
            return Category::all()->toArray();
        });
    }
}
