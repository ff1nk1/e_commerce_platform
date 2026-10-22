<?php

namespace App\Services;

use App\Events\SearchPerformed;
use App\Search\ProductAutocompleteQuery;
use App\Search\ProductSearchQueryBuilder;
use App\Search\ProductSearchResponseMapper;
use OpenSearch\Client;

class ProductSearchService
{
    private const MIN_AUTOCOMPLETE_CHARS = 2;

    public function __construct(
        private readonly Client $client,
        private readonly SearchIndexService $index,
        private readonly ProductSearchQueryBuilder $queryBuilder,
        private readonly ProductSearchResponseMapper $responseMapper,
        private readonly ProductAutocompleteQuery $autocompleteQuery,
    ) {}

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
     * @param  bool  $skipAnalytics  When true, still publishes search.performed but marks skip_analytics for consumers.
     * @return array<string, mixed>
     */
    public function search(array $params, bool $skipAnalytics = false): array
    {
        $this->index->ensureIndices();

        $page = $params['page'];
        $perPage = $params['per_page'];

        $response = $this->client->search([
            'index' => (string) config('opensearch.indices.products', 'products'),
            'body' => $this->queryBuilder->buildSearchBody($params),
        ]);

        $total = (int) ($response['hits']['total']['value'] ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));

        $result = [
            'products' => [
                'data' => $this->responseMapper->mapHits($response),
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'facets' => $this->responseMapper->mapFacets($response['aggregations'] ?? []),
            'applied' => [
                'q' => $params['q'],
                'filters' => [
                    'category_ids' => $params['category_ids'],
                    'min_price' => $params['min_price'],
                    'max_price' => $params['max_price'],
                    'in_stock' => $params['in_stock'],
                    'attributes' => $params['attributes'],
                ],
                'sort' => $params['sort'],
            ],
        ];

        event(new SearchPerformed($params, $total, $skipAnalytics));

        return $result;
    }

    /**
     * Prefix-friendly product name suggestions (no facets / no search.performed).
     *
     * @return array{suggestions: list<array{id: int, name: string, price: float|null, image_url: string|null}>}
     */
    public function autocomplete(?string $q, int $limit = 8, string|int $fuzziness = 'AUTO'): array
    {
        $this->index->ensureIndices();

        $q = is_string($q) ? trim($q) : '';
        if (mb_strlen($q) < self::MIN_AUTOCOMPLETE_CHARS) {
            return ['suggestions' => []];
        }

        $response = $this->client->search([
            'index' => (string) config('opensearch.indices.products', 'products'),
            'body' => $this->autocompleteQuery->buildBody($q, $limit, $fuzziness),
        ]);

        return ['suggestions' => $this->autocompleteQuery->mapSuggestions($response)];
    }
}
