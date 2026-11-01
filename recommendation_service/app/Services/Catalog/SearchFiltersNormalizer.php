<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTO\Catalog\AttributeConstraint;
use App\DTO\Chat\SearchFilters;

final class SearchFiltersNormalizer
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
     * @param  list<AttributeConstraint>  $attributes
     * @return list<AttributeConstraint>
     */
    private function normalizeAttrRows(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $row) {
            $value = trim($row->value);
            if ($value === '') {
                continue;
            }
            if ($row->valueOnly) {
                $out[] = new AttributeConstraint(
                    name: trim($row->name),
                    value: $value,
                    valueOnly: true,
                );

                continue;
            }
            $name = $this->attributeNames->canonicalize($row->name);
            if ($name === '') {
                continue;
            }
            $out[] = new AttributeConstraint(name: $name, value: $value);
        }

        return $out;
    }
}
