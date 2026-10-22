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

        $defaultInStockWhenMissing = $this->requireInStock ? true : null;
        $resolvedInStock = $filters->inStock ?? $defaultInStockWhenMissing;

        return new SearchFilters(
            storeId: $filters->storeId,
            storeName: $storeName !== '' ? $storeName : null,
            categoryName: $categoryName !== '' ? $categoryName : null,
            inStock: $resolvedInStock,
            priceMin: $priceMin,
            priceMax: $priceMax,
            query: $query !== '' ? $query : null,
            attributes: $this->normalizeAttrRows($filters->attributes),
            requiredAttributes: $this->normalizeAttrRows($filters->requiredAttributes),
            preferredAttributes: $this->normalizeAttrRows($filters->preferredAttributes),
        );
    }

    /**
     * @param  list<array{name: string, value: string, value_only?: bool}>  $attributes
     * @return list<array{name: string, value: string, value_only?: bool}>
     */
    private function normalizeAttrRows(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $row) {
            $valueOnly = (bool) ($row['value_only'] ?? false);
            $value = trim((string) $row['value']);
            if ($value === '') {
                continue;
            }
            if ($valueOnly) {
                $out[] = [
                    'name' => trim((string) $row['name']),
                    'value' => $value,
                    'value_only' => true,
                ];

                continue;
            }
            $name = $this->attributeNames->canonicalize((string) $row['name']);
            if ($name === '') {
                continue;
            }
            $out[] = ['name' => $name, 'value' => $value];
        }

        return $out;
    }
}
