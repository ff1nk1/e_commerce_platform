<?php

namespace App\Services\Catalog;

use App\Services\Llm\DTOs\SearchFilters;

class SearchFiltersNormalizer
{
    public function __construct(
        private readonly bool $requireInStock = true,
        private readonly AttributeNameNormalizer $attributeNames = new AttributeNameNormalizer,
    ) {}

    public function normalize(SearchFilters $filters): SearchFilters
    {
        $storeName = $filters->storeName !== null ? trim($filters->storeName) : null;
        $categoryName = $filters->categoryName !== null ? trim($filters->categoryName) : null;
        $query = $filters->query !== null ? trim($filters->query) : null;

        $priceMin = $filters->priceMin;
        $priceMax = $filters->priceMax;
        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }

        return new SearchFilters(
            storeId: $filters->storeId,
            storeName: $storeName !== '' ? $storeName : null,
            categoryName: $categoryName !== '' ? $categoryName : null,
            inStock: $filters->inStock ?? ($this->requireInStock ? true : null),
            priceMin: $priceMin,
            priceMax: $priceMax,
            query: $query !== '' ? $query : null,
            attributes: $this->attributeNames->normalizeList($filters->attributes),
            requiredAttributes: $this->attributeNames->normalizeList($filters->requiredAttributes),
            preferredAttributes: $this->attributeNames->normalizeList($filters->preferredAttributes),
        );
    }
}
