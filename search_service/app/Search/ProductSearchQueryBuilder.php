<?php

namespace App\Search;

class ProductSearchQueryBuilder
{
    /** @var list<array{key: string, from: float|null, to: float|null, label: string}> */
    public const PRICE_RANGES = [
        ['key' => '0-500', 'from' => 0.0, 'to' => 500.0, 'label' => '0–500'],
        ['key' => '500-1000', 'from' => 500.0, 'to' => 1000.0, 'label' => '500–1000'],
        ['key' => '1000-5000', 'from' => 1000.0, 'to' => 5000.0, 'label' => '1000–5000'],
        ['key' => '5000+', 'from' => 5000.0, 'to' => null, 'label' => '5000+'],
    ];

    /**
     * @param  array{
     *     q: string|null,
     *     category_ids: list<int>,
     *     min_price: float|null,
     *     max_price: float|null,
     *     in_stock: bool|null,
     *     attributes: array<string, list<string>>,
     *     page: int,
     *     per_page: int,
     *     sort: string
     * }  $params
     * @return array<string, mixed>
     */
    public function buildSearchBody(array $params): array
    {
        $page = $params['page'];
        $perPage = $params['per_page'];
        $from = ($page - 1) * $perPage;

        $body = [
            'from' => $from,
            'size' => $perPage,
            'track_total_hits' => (int) config('opensearch.track_total_hits', 10000),
            'query' => [
                'bool' => [
                    'must' => [$this->buildTextQuery($params['q'])],
                ],
            ],
            'sort' => $this->buildSort($params['sort'], $params['q']),
            // Facets are global (full catalog) so filter options stay stable across queries.
            'aggs' => $this->buildFacetAggregations(),
        ];

        $allFilters = $this->buildFilters($params);
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
        if ($q === null || $q === '') {
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
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function buildFilters(array $params): array
    {
        $filters = [];

        if ($params['category_ids'] !== []) {
            $filters[] = ['terms' => ['category_id' => $params['category_ids']]];
        }

        $range = [];
        if ($params['min_price'] !== null) {
            $range['gte'] = $params['min_price'];
        }
        if ($params['max_price'] !== null) {
            $range['lte'] = $params['max_price'];
        }
        if ($range !== []) {
            $filters[] = ['range' => ['price' => $range]];
        }

        if ($params['in_stock'] !== null) {
            $filters[] = ['term' => ['in_stock' => $params['in_stock']]];
        }

        // Multiple values for the same attribute are AND (separate nested clauses).
        foreach ($params['attributes'] as $name => $values) {
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
    public function buildSort(string $sort, ?string $q): array
    {
        return match ($sort) {
            'price_asc' => [['price' => ['order' => 'asc']], ['id' => ['order' => 'desc']]],
            'price_desc' => [['price' => ['order' => 'desc']], ['id' => ['order' => 'desc']]],
            'new' => [['id' => ['order' => 'desc']]],
            default => $q
                ? [['_score' => ['order' => 'desc']], ['id' => ['order' => 'desc']]]
                : [['id' => ['order' => 'desc']]],
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
                        'terms' => ['field' => 'category_id', 'size' => 100],
                        'aggs' => [
                            'name' => [
                                'terms' => ['field' => 'category_name.keyword', 'size' => 1],
                            ],
                        ],
                    ],
                    'price_ranges' => [
                        'filters' => [
                            'filters' => $priceFilters,
                        ],
                    ],
                    'availability' => [
                        'terms' => ['field' => 'in_stock', 'size' => 2],
                    ],
                    'attributes' => [
                        'nested' => ['path' => 'attributes'],
                        'aggs' => [
                            'names' => [
                                'terms' => ['field' => 'attributes.name', 'size' => 50],
                                'aggs' => [
                                    'values' => [
                                        'terms' => ['field' => 'attributes.value', 'size' => 50],
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
