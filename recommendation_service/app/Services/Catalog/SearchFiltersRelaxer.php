<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\Llm\DTOs\SearchFilters;
use App\Support\RuText;

/**
 * Progressive filter relaxation when a hard search returns no hits.
 */
final class SearchFiltersRelaxer
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
                    'filters' => $filters->withAttributeBuckets(
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
                    'filters' => $filters->withAttributeBuckets(
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
                'filters' => $filters->withoutStoreName(),
                'relaxed' => 'store_name='.$filters->storeName,
            ];
        }

        if ($filters->priceMin !== null || $filters->priceMax !== null) {
            $relaxedPriceLabel = 'price';
            if ($filters->priceMin !== null) {
                $relaxedPriceLabel .= ' min='.$filters->priceMin;
            }
            if ($filters->priceMax !== null) {
                $relaxedPriceLabel .= ' max='.$filters->priceMax;
            }

            return [
                'filters' => $filters->withoutPriceRange(),
                'relaxed' => trim($relaxedPriceLabel),
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
        $key = RuText::fold($canonical);

        foreach ($attributes as $row) {
            $nameKey = RuText::fold((string) $row['name']);
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
