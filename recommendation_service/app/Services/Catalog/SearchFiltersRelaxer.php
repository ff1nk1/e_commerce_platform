<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTO\Catalog\AttributeConstraint;
use App\DTO\Catalog\RelaxationStep;
use App\DTO\Chat\SearchFilters;
use App\Support\RuText;

/**
 * Progressive filter relaxation when a hard search returns no hits.
 */
final class SearchFiltersRelaxer
{
    private const PREFERRED_DROP_ORDER = ['Материал', 'Бренд', 'Цвет'];

    /**
     * Drop one constraint. Returns null when nothing left to relax.
     */
    public function relaxOnce(SearchFilters $filters): ?RelaxationStep
    {
        foreach (self::PREFERRED_DROP_ORDER as $canonical) {
            $fromPreferred = $this->dropNamedAttribute($filters->preferredAttributes, $canonical);
            if ($fromPreferred !== null) {
                return new RelaxationStep(
                    filters: $filters->withAttributeBuckets(
                        attributes: $filters->attributes,
                        requiredAttributes: $filters->requiredAttributes,
                        preferredAttributes: $fromPreferred['kept'],
                    ),
                    relaxed: $fromPreferred['label'],
                );
            }

            $fromRequired = $this->dropNamedAttribute($filters->requiredAttributes, $canonical);
            if ($fromRequired !== null) {
                return new RelaxationStep(
                    filters: $filters->withAttributeBuckets(
                        attributes: $filters->attributes,
                        requiredAttributes: $fromRequired['kept'],
                        preferredAttributes: $filters->preferredAttributes,
                    ),
                    relaxed: $fromRequired['label'],
                );
            }
        }

        if ($filters->storeName !== null) {
            return new RelaxationStep(
                filters: $filters->withoutStoreName(),
                relaxed: 'store_name='.$filters->storeName,
            );
        }

        if ($filters->priceMin !== null || $filters->priceMax !== null) {
            $relaxedPriceLabel = 'price';
            if ($filters->priceMin !== null) {
                $relaxedPriceLabel .= ' min='.$filters->priceMin;
            }
            if ($filters->priceMax !== null) {
                $relaxedPriceLabel .= ' max='.$filters->priceMax;
            }

            return new RelaxationStep(
                filters: $filters->withoutPriceRange(),
                relaxed: trim($relaxedPriceLabel),
            );
        }

        return null;
    }

    /**
     * @param  list<AttributeConstraint>  $attributes
     * @return array{kept: list<AttributeConstraint>, label: string}|null
     */
    private function dropNamedAttribute(array $attributes, string $canonical): ?array
    {
        $kept = [];
        $dropped = null;
        $key = RuText::fold($canonical);

        foreach ($attributes as $row) {
            $nameKey = RuText::fold($row->name);
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
            'label' => $dropped->name.'='.$dropped->value,
        ];
    }
}
