<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DTO\Search\ProductSearchQuery;
use App\DTO\Search\ProductSearchResult;
use App\Events\SearchPerformed;
use App\Infrastructure\OpenSearch\ProductSearchQueryBuilder;
use App\Infrastructure\OpenSearch\ProductSearchResponseMapper;
use OpenSearch\Client;

final class SearchProductsService
{
    public function __construct(
        private readonly Client $client,
        private readonly ProductSearchQueryBuilder $queryBuilder,
        private readonly ProductSearchResponseMapper $responseMapper,
    ) {}

    /**
     * @param  bool  $skipAnalytics  When true, still publishes search.performed but marks skip_analytics for consumers.
     */
    public function handle(ProductSearchQuery $query, bool $skipAnalytics = false): ProductSearchResult
    {
        $page = $query->page;
        $perPage = $query->perPage;

        $response = $this->client->search([
            'index' => (string) config('opensearch.indices.products', 'products'),
            'body' => $this->queryBuilder->buildSearchBody($query),
        ]);

        $total = (int) ($response['hits']['total']['value'] ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));

        $result = new ProductSearchResult(
            products: [
                'data' => $this->responseMapper->mapHits($response),
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            facets: $this->responseMapper->mapFacets($response['aggregations'] ?? []),
            applied: [
                'q' => $query->q,
                'filters' => [
                    'category_ids' => $query->categoryIds,
                    'min_price' => $query->minPrice,
                    'max_price' => $query->maxPrice,
                    'in_stock' => $query->inStock,
                    'attributes' => $query->attributes,
                ],
                'sort' => $query->sort->value,
            ],
        );

        event(new SearchPerformed($query, $total, $skipAnalytics));

        return $result;
    }
}
