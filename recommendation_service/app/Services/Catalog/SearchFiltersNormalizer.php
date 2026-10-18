<?php

namespace App\Services\Catalog;

use App\Services\Llm\DTOs\SearchFilters;

class SearchFiltersNormalizer
{
    public function __construct(
        private readonly bool $requireInStock = true,
    ) {}

    public function normalize(SearchFilters $filters): SearchFilters
    {
        $storeName = $filters->storeName !== null ? trim($filters->storeName) : null;
        $categoryName = $filters->categoryName !== null ? trim($filters->categoryName) : null;
        $query = $filters->query !== null ? trim($filters->query) : null;

        return new SearchFilters(
            storeId: $filters->storeId,
            storeName: $storeName !== '' ? $storeName : null,
            categoryName: $categoryName !== '' ? $categoryName : null,
            inStock: $filters->inStock ?? ($this->requireInStock ? true : null),
            priceMin: $filters->priceMin,
            priceMax: $filters->priceMax,
            query: $query !== '' ? $query : null,
            attributes: $filters->attributes,
        );
    }
}
