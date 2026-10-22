<?php

namespace App\Services\Catalog;

use App\Services\Llm\DTOs\SearchFilters;

/**
 * Progressive filter relaxation when a hard search returns no hits.
 */
class SearchFiltersRelaxer
{
    private const PREFERRED_DROP_ORDER = ['Материал', 'Бренд', 'Цвет'];

    /**
     * Drop one constraint. Returns null when nothing left to relax.
     *
     * @return array{filters: SearchFilters, relaxed: string}|null
     */
    public function relaxOnce(SearchFilters $filters): ?array
    {
        foreach (self::PREFERRED_DROP_ORDER as $canonical) {
            $fromPreferred = $this->dropNamedAttribute($filters->preferredAttributes, $canonical);
            if ($fromPreferred !== null) {
                return [
                    'filters' => new SearchFilters(
                        storeId: $filters->storeId,
                        storeName: $filters->storeName,
                        categoryName: $filters->categoryName,
                        inStock: $filters->inStock,
                        priceMin: $filters->priceMin,
                        priceMax: $filters->priceMax,
                        query: $filters->query,
                        attributes: $filters->attributes,
                        requiredAttributes: $filters->requiredAttributes,
                        preferredAttributes: $fromPreferred['kept'],
                    ),
                    'relaxed' => $fromPreferred['label'],
                ];
            }

            $fromRequired = $this->dropNamedAttribute($filters->requiredAttributes, $canonical);
            if ($fromRequired !== null) {
                return [
                    'filters' => new SearchFilters(
                        storeId: $filters->storeId,
                        storeName: $filters->storeName,
                        categoryName: $filters->categoryName,
                        inStock: $filters->inStock,
                        priceMin: $filters->priceMin,
                        priceMax: $filters->priceMax,
                        query: $filters->query,
                        attributes: $filters->attributes,
                        requiredAttributes: $fromRequired['kept'],
                        preferredAttributes: $filters->preferredAttributes,
                    ),
                    'relaxed' => $fromRequired['label'],
                ];
            }
        }

        if ($filters->storeName !== null) {
            return [
                'filters' => new SearchFilters(
                    storeId: $filters->storeId,
                    storeName: null,
                    categoryName: $filters->categoryName,
                    inStock: $filters->inStock,
                    priceMin: $filters->priceMin,
                    priceMax: $filters->priceMax,
                    query: $filters->query,
                    attributes: $filters->attributes,
                    requiredAttributes: $filters->requiredAttributes,
                    preferredAttributes: $filters->preferredAttributes,
                ),
                'relaxed' => 'store_name='.$filters->storeName,
            ];
        }

        if ($filters->priceMin !== null || $filters->priceMax !== null) {
            $label = 'price';
            if ($filters->priceMin !== null) {
                $label .= ' min='.$filters->priceMin;
            }
            if ($filters->priceMax !== null) {
                $label .= ' max='.$filters->priceMax;
            }

            return [
                'filters' => new SearchFilters(
                    storeId: $filters->storeId,
                    storeName: $filters->storeName,
                    categoryName: $filters->categoryName,
                    inStock: $filters->inStock,
                    priceMin: null,
                    priceMax: null,
                    query: $filters->query,
                    attributes: $filters->attributes,
                    requiredAttributes: $filters->requiredAttributes,
                    preferredAttributes: $filters->preferredAttributes,
                ),
                'relaxed' => trim($label),
            ];
        }

        return null;
    }

    /**
     * @param  list<array{name: string, value: string}>  $attributes
     * @return array{kept: list<array{name: string, value: string}>, label: string}|null
     */
    private function dropNamedAttribute(array $attributes, string $canonical): ?array
    {
        $kept = [];
        $dropped = null;
        $key = \App\Support\RuText::fold($canonical);

        foreach ($attributes as $row) {
            $nameKey = \App\Support\RuText::fold((string) $row['name']);
            if ($dropped === null && $nameKey === $key) {
                $dropped = $row;

                continue;
            }
            $kept[] = $row;
        }

        if ($dropped === null) {
            return null;
        }

        return [
            'kept' => $kept,
            'label' => $dropped['name'].'='.$dropped['value'],
        ];
    }
}
