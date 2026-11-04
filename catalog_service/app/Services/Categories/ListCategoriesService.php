<?php

declare(strict_types=1);

namespace App\Services\Categories;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Support\Facades\Cache;

final class ListCategoriesService
{
    private const ALL_CATEGORIES_CACHE_TTL_SECONDS = 3600;

    /**
     * Получение всех категорий из кэша. Поля проходят через
     * {@see CategoryResource}, поэтому внутренние атрибуты наружу не уходят.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(): array
    {
        return Cache::tags(['categories'])->remember('all_categories', self::ALL_CATEGORIES_CACHE_TTL_SECONDS, function (): array {
            /** @var array<int, array<string, mixed>> $categories */
            $categories = CategoryResource::collection(Category::all())->resolve();

            return $categories;
        });
    }
}
