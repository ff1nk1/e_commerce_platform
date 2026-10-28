<?php

declare(strict_types=1);

namespace App\Support;

final class CatalogCacheKeys
{
    /**
     * @param  array<string, mixed>  $queryParams
     */
    public static function categoryProducts(string $catSlug, array $queryParams): string
    {
        ksort($queryParams);
        $queryString = md5((string) json_encode($queryParams));

        return "category_products_{$catSlug}_{$queryString}";
    }

    public static function product(int|string $id): string
    {
        return "product_{$id}";
    }
}
