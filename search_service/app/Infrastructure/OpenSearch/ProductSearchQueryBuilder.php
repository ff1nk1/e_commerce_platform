<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenSearch;

use App\DTO\Search\ProductSearchQuery;
use App\Enums\ProductSort;

final class ProductSearchQueryBuilder
{
    private const CATEGORY_AGG_SIZE = 100;

    private const CATEGORY_NAME_AGG_SIZE = 1;

    private const AVAILABILITY_AGG_SIZE = 2;

    private const ATTR_NAME_AGG_SIZE = 50;

    private const ATTR_VALUE_AGG_SIZE = 50;

    /** @var list<array{key: string, from: float|null, to: float|null, label: string}> */
    public const PRICE_RANGES = [
        ['key' => '0-500', 'from' => 0.0, 'to' => 500.0, 'label' => '0–500'],
        ['key' => '500-1000', 'from' => 500.0, 'to' => 1000.0, 'label' => '500–1000'],
        ['key' => '1000-5000', 'from' => 1000.0, 'to' => 5000.0, 'label' => '1000–5000'],
        ['key' => '5000+', 'from' => 5000.0, 'to' => null, 'label' => '5000+'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function buildSearchBody(ProductSearchQuery $query): array
    {
        $page = $query->page;
        $perPage = $query->perPage;
        $from = ($page - 1) * $perPage;

        $body = [
            'from' => $from,
            'size' => $perPage,
            'track_total_hits' => (int) config('opensearch.track_total_hits', 10000),
            'query' => [
                'bool' => [
                    'must' => [$this->buildTextQuery($query->q)],
                ],
            ],
            'sort' => $this->buildSort($query->sort, $query->q),
            // Facets are global (full catalog) so filter options stay stable across queries.
            'aggs' => $this->buildFacetAggregations(),
        ];

        $allFilters = $this->buildFilters($query);
        if ($allFilters !== []) {
            $body['post_filter'] = [
                'bool' => [
                    'filter' => $allFilters,
                ],
            ];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTextQuery(?string $q): array
    {
        $hasQuery = $q !== null && $q !== '';
        if (! $hasQuery) {
            return ['match_all' => (object) []];
        }

        return [
            'multi_match' => [
                'query' => $q,
                'fields' => ['name^3', 'description', 'category_name', 'store_name'],
                'type' => 'best_fields',
                'operator' => 'and',
                'fuzziness' => 'AUTO',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildFilters(ProductSearchQuery $query): array
    {
        $filters = [
            [
                'bool' => [
                    'must_not' => [
                        ['term' => ['is_published' => false]],
                    ],
                ],
            ],
        ];

        if ($query->categoryIds !== []) {
            $filters[] = ['terms' => ['category_id' => $query->categoryIds]];
        }

        $range = [];
        if ($query->minPrice !== null) {
            $range['gte'] = $query->minPrice;
        }
        if ($query->maxPrice !== null) {
            $range['lte'] = $query->maxPrice;
        }
        if ($range !== []) {
            $filters[] = ['range' => ['price' => $range]];
        }

        if ($query->inStock !== null) {
            $filters[] = ['term' => ['in_stock' => $query->inStock]];
        }

        // Multiple values for the same attribute are AND (separate nested clauses).
        foreach ($query->attributes as $name => $values) {
            foreach ($values as $value) {
                $filters[] = [
                    'nested' => [
                        'path' => 'attributes',
                        'query' => [
                            'bool' => [
                                'filter' => [
                                    ['term' => ['attributes.name' => $name]],
                                    ['term' => ['attributes.value' => $value]],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        }

        return $filters;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildSort(ProductSort $sort, ?string $q): array
    {
        $relevanceSort = [['_score' => ['order' => 'desc']], ['id' => ['order' => 'desc']]];
        $idDescSort = [['id' => ['order' => 'desc']]];

        return match ($sort) {
            ProductSort::PriceAsc => [['price' => ['order' => 'asc']], ['id' => ['order' => 'desc']]],
            ProductSort::PriceDesc => [['price' => ['order' => 'desc']], ['id' => ['order' => 'desc']]],
            ProductSort::New => $idDescSort,
            ProductSort::Relevance => $q ? $relevanceSort : $idDescSort,
        };
    }

    /**
     * Global catalog facets — independent of q / applied filters so the sidebar
     * always lists the same options for every search.
     *
     * @return array<string, mixed>
     */
    public function buildFacetAggregations(): array
    {
        $priceFilters = [];
        foreach (self::PRICE_RANGES as $range) {
            $rangeBody = ['gte' => $range['from']];
            if ($range['to'] !== null) {
                $rangeBody['lt'] = $range['to'];
            }
            $priceFilters[$range['key']] = ['range' => ['price' => $rangeBody]];
        }

        return [
            'catalog_facets' => [
                'global' => (object) [],
                'aggs' => [
                    'categories' => [
                        'terms' => ['field' => 'category_id', 'size' => self::CATEGORY_AGG_SIZE],
                        'aggs' => [
                            'name' => [
                                'terms' => ['field' => 'category_name.keyword', 'size' => self::CATEGORY_NAME_AGG_SIZE],
                            ],
                        ],
                    ],
                    'price_ranges' => [
                        'filters' => [
                            'filters' => $priceFilters,
                        ],
                    ],
                    'availability' => [
                        'terms' => ['field' => 'in_stock', 'size' => self::AVAILABILITY_AGG_SIZE],
                    ],
                    'attributes' => [
                        'nested' => ['path' => 'attributes'],
                        'aggs' => [
                            'names' => [
                                'terms' => ['field' => 'attributes.name', 'size' => self::ATTR_NAME_AGG_SIZE],
                                'aggs' => [
                                    'values' => [
                                        'terms' => ['field' => 'attributes.value', 'size' => self::ATTR_VALUE_AGG_SIZE],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
