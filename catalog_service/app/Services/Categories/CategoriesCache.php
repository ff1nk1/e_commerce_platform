<?php

declare(strict_types=1);

namespace App\Services\Categories;

use Illuminate\Support\Facades\Cache;

final class CategoriesCache
{
    public function clear(): void
    {
        Cache::tags(['categories'])->flush();
    }
}
