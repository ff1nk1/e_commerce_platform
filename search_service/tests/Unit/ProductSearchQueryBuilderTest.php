<?php

namespace Tests\Unit;

use App\Services\ProductSearchService;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Facades\Kafka;
use Mockery;
use Tests\Concerns\CapturesOpenSearchRequests;
use Tests\TestCase;

class ProductSearchQueryBuilderTest extends TestCase
{
    use CapturesOpenSearchRequests;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_empty_query_uses_match_all_without_post_filter(): void
    {
        $this->mockOpenSearchClient();
        Kafka::fake();

        app(ProductSearchService::class)->search($this->searchParams([
            'q' => null,
            'sort' => 'new',
        ]));

        $body = $this->lastSearchBody();

        $this->assertArrayHasKey('match_all', $body['query']['bool']['must'][0]);
        $this->assertArrayNotHasKey('post_filter', $body);
        $this->assertSame([['id' => ['order' => 'desc']]], $body['sort']);
        $this->assertSame(10000, $body['track_total_hits']);
    }

    public function test_text_query_multi_match_shape(): void
    {
        $this->mockOpenSearchClient();
        Kafka::fake();

        app(ProductSearchService::class)->search($this->searchParams([
            'q' => 'wireless headphones',
            'sort' => 'relevance',
        ]));

        $must = $this->lastSearchBody()['query']['bool']['must'][0];
        $mm = $must['multi_match'];

        $this->assertSame('wireless headphones', $mm['query']);
        $this->assertSame(['name^3', 'description', 'category_name', 'store_name'], $mm['fields']);
        $this->assertSame('best_fields', $mm['type']);
        $this->assertSame('and', $mm['operator']);
        $this->assertSame('AUTO', $mm['fuzziness']);
    }

    public function test_category_price_stock_and_attribute_filters_in_post_filter(): void
    {
        $this->mockOpenSearchClient();
        Kafka::fake();

        app(ProductSearchService::class)->search($this->searchParams([
            'q' => 'phone',
            'category_ids' => [3, 5],
            'min_price' => 100.0,
            'max_price' => 500.0,
            'in_stock' => true,
            'attributes' => [
                'Color' => ['Black', 'Red'],
                'Size' => ['M'],
            ],
            'sort' => 'relevance',
        ]));

        $filters = $this->lastSearchBody()['post_filter']['bool']['filter'];

        $this->assertContains(['terms' => ['category_id' => [3, 5]]], $filters);
        $this->assertContains(['range' => ['price' => ['gte' => 100.0, 'lte' => 500.0]]], $filters);
        $this->assertContains(['term' => ['in_stock' => true]], $filters);

        $nested = array_values(array_filter($filters, fn ($f) => isset($f['nested'])));
        $this->assertCount(3, $nested);

        foreach ($nested as $clause) {
            $this->assertArrayHasKey('nested', $clause);
            $terms = $clause['nested']['query']['bool']['filter'];
            $this->assertCount(2, $terms);
        }
    }

    public function test_min_price_only_and_max_price_only_range_filters(): void
    {
        $this->mockOpenSearchClient();
        Kafka::fake();

        app(ProductSearchService::class)->search($this->searchParams([
            'min_price' => 50.0,
        ]));
        $this->assertContains(
            ['range' => ['price' => ['gte' => 50.0]]],
            $this->lastSearchBody()['post_filter']['bool']['filter']
        );

        app(ProductSearchService::class)->search($this->searchParams([
            'max_price' => 999.0,
        ]));
        $this->assertContains(
            ['range' => ['price' => ['lte' => 999.0]]],
            $this->lastSearchBody()['post_filter']['bool']['filter']
        );
    }

    public function test_sort_variants(): void
    {
        $this->mockOpenSearchClient();
        Kafka::fake();
        $service = app(ProductSearchService::class);

        $service->search($this->searchParams(['q' => 'x', 'sort' => 'relevance']));
        $this->assertSame(
            [['_score' => ['order' => 'desc']], ['id' => ['order' => 'desc']]],
            $this->lastSearchBody()['sort']
        );

        $service->search($this->searchParams(['sort' => 'price_asc']));
        $this->assertSame(
            [['price' => ['order' => 'asc']], ['id' => ['order' => 'desc']]],
            $this->lastSearchBody()['sort']
        );

        $service->search($this->searchParams(['sort' => 'price_desc']));
        $this->assertSame(
            [['price' => ['order' => 'desc']], ['id' => ['order' => 'desc']]],
            $this->lastSearchBody()['sort']
        );

        $service->search($this->searchParams(['sort' => 'new']));
        $this->assertSame(
            [['id' => ['order' => 'desc']]],
            $this->lastSearchBody()['sort']
        );

        // unknown sort + empty q → id desc (same shape as "new"; assert via body key presence)
        $beforeCount = count($this->capturedSearchRequests);
        $service->search($this->searchParams(['q' => null, 'sort' => 'relevance']));
        $this->assertCount($beforeCount + 1, $this->capturedSearchRequests);
        $this->assertSame(
            [['id' => ['order' => 'desc']]],
            $this->capturedSearchRequests[array_key_last($this->capturedSearchRequests)]['body']['sort']
        );
    }

    public function test_pagination_from_and_size(): void
    {
        $this->mockOpenSearchClient();
        Kafka::fake();

        app(ProductSearchService::class)->search($this->searchParams([
            'page' => 3,
            'per_page' => 15,
        ]));

        $body = $this->lastSearchBody();
        $this->assertSame(30, $body['from']);
        $this->assertSame(15, $body['size']);
    }

    public function test_facet_aggregations_are_global_with_expected_keys(): void
    {
        $this->mockOpenSearchClient();
        Kafka::fake();

        app(ProductSearchService::class)->search($this->searchParams([
            'category_ids' => [1],
            'q' => 'phone',
            'sort' => 'relevance',
        ]));

        $aggs = $this->lastSearchBody()['aggs']['catalog_facets'];
        $this->assertIsObject($aggs['global']);
        $this->assertArrayHasKey('categories', $aggs['aggs']);
        $this->assertArrayHasKey('price_ranges', $aggs['aggs']);
        $this->assertArrayHasKey('availability', $aggs['aggs']);
        $this->assertArrayHasKey('attributes', $aggs['aggs']);

        $priceFilters = $aggs['aggs']['price_ranges']['filters']['filters'];
        $this->assertSame(['0-500', '500-1000', '1000-5000', '5000+'], array_keys($priceFilters));
        $this->assertSame(['gte' => 0.0, 'lt' => 500.0], $priceFilters['0-500']['range']['price']);
        $this->assertSame(['gte' => 5000.0], $priceFilters['5000+']['range']['price']);

        $this->assertArrayHasKey('path', $aggs['aggs']['attributes']['nested']);
        $this->assertArrayHasKey('post_filter', $this->lastSearchBody());
    }

    public function test_map_facets_from_aggregation_buckets(): void
    {
        $this->mockOpenSearchClient($this->emptySearchResponse([
            'categories' => [
                'buckets' => [
                    [
                        'key' => 3,
                        'doc_count' => 10,
                        'name' => ['buckets' => [['key' => 'Gadgets']]],
                    ],
                ],
            ],
            'price_ranges' => [
                'buckets' => [
                    '0-500' => ['doc_count' => 4],
                    '500-1000' => ['doc_count' => 2],
                    '1000-5000' => ['doc_count' => 1],
                    '5000+' => ['doc_count' => 0],
                ],
            ],
            'availability' => [
                'buckets' => [
                    ['key' => 1, 'doc_count' => 7],
                    ['key' => 0, 'doc_count' => 3],
                ],
            ],
            'attributes' => [
                'names' => [
                    'buckets' => [
                        [
                            'key' => 'Color',
                            'doc_count' => 5,
                            'values' => [
                                'buckets' => [
                                    ['key' => 'Black', 'doc_count' => 3],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        Kafka::fake();

        $result = app(ProductSearchService::class)->search($this->searchParams());

        $this->assertSame([
            ['id' => 3, 'name' => 'Gadgets', 'count' => 10],
        ], $result['facets']['categories']);
        $this->assertSame('0-500', $result['facets']['price_ranges'][0]['key']);
        $this->assertSame(4, $result['facets']['price_ranges'][0]['count']);
        $this->assertTrue($result['facets']['availability'][0]['value']);
        $this->assertFalse($result['facets']['availability'][1]['value']);
        $this->assertSame([
            ['value' => 'Black', 'count' => 3],
        ], $result['facets']['attributes']['Color']);
    }

    public function test_autocomplete_short_query_skips_search(): void
    {
        $this->mockOpenSearchClient();

        $result = app(ProductSearchService::class)->autocomplete('a');

        $this->assertArrayHasKey('suggestions', $result);
        $this->assertCount(0, $result['suggestions']);
        $this->assertCount(0, $this->capturedSearchRequests);
    }

    public function test_autocomplete_builds_bool_prefix_query_and_clamps_limit(): void
    {
        $this->mockOpenSearchClient([
            'hits' => [
                'hits' => [
                    [
                        '_id' => '1',
                        '_source' => [
                            'id' => 1,
                            'name' => 'Phone X',
                            'price' => 99.5,
                            'image_url' => null,
                        ],
                    ],
                ],
            ],
        ]);

        $result = app(ProductSearchService::class)->autocomplete('ph', 100);

        $body = $this->lastSearchBody();
        $this->assertSame(20, $body['size']);
        $this->assertSame(['id', 'name', 'price', 'image_url'], $body['_source']);
        $mm = $body['query']['multi_match'];
        $this->assertSame('ph', $mm['query']);
        $this->assertSame('bool_prefix', $mm['type']);
        $this->assertSame([
            'name.autocomplete',
            'name.autocomplete._2gram',
            'name.autocomplete._3gram',
        ], $mm['fields']);
        $this->assertSame([
            [
                'id' => 1,
                'name' => 'Phone X',
                'price' => 99.5,
                'image_url' => null,
            ],
        ], $result['suggestions']);
    }

    public function test_search_publishes_search_performed_event(): void
    {
        $this->mockOpenSearchClient([
            'hits' => [
                'total' => ['value' => 2],
                'hits' => [],
            ],
            'aggregations' => $this->emptySearchResponse()['aggregations'],
        ]);
        Kafka::fake();

        app(ProductSearchService::class)->search($this->searchParams([
            'q' => 'laptop',
            'category_ids' => [2],
            'min_price' => 10.0,
            'max_price' => null,
            'in_stock' => false,
            'attributes' => ['Brand' => ['Acme']],
            'sort' => 'price_asc',
        ]));

        Kafka::assertPublishedOn('search.events', null, function ($message) {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'search.performed'
                && ($headers['source'] ?? null) === 'search-service'
                && ! isset($headers['skip-analytics'])
                && ($body['event'] ?? null) === 'search.performed'
                && ($body['event_type'] ?? null) === 'search.performed'
                && ($body['skip_analytics'] ?? null) === false
                && ($body['data']['query'] ?? null) === 'laptop'
                && ($body['data']['results_count'] ?? null) === 2
                && ($body['data']['sort'] ?? null) === 'price_asc'
                && ($body['data']['filters']['category_ids'] ?? null) === [2]
                && ($body['data']['filters']['in_stock'] ?? null) === false
                && ($body['data']['filters']['attributes'] ?? null) === ['Brand' => ['Acme']];
        });
    }

    public function test_search_publishes_skip_analytics_when_flagged(): void
    {
        $this->mockOpenSearchClient([
            'hits' => [
                'total' => ['value' => 0],
                'hits' => [],
            ],
            'aggregations' => $this->emptySearchResponse()['aggregations'],
        ]);
        Kafka::fake();

        app(ProductSearchService::class)->search($this->searchParams([
            'q' => 'loadtest',
            'sort' => 'relevance',
        ]), skipAnalytics: true);

        Kafka::assertPublishedOn('search.events', null, function ($message) {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'search.performed'
                && ($headers['skip-analytics'] ?? null) === 'true'
                && ($body['skip_analytics'] ?? null) === true
                && ($body['data']['query'] ?? null) === 'loadtest';
        });
    }

    public function test_search_succeeds_when_kafka_publish_fails(): void
    {
        $this->mockOpenSearchClient([
            'hits' => [
                'total' => ['value' => 1],
                'hits' => [
                    [
                        '_id' => '9',
                        '_source' => [
                            'id' => 9,
                            'name' => 'Resilient',
                            'price' => 1,
                            'in_stock' => true,
                            'quantity' => 1,
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
            'aggregations' => $this->emptySearchResponse()['aggregations'],
        ]);

        Kafka::shouldReceive('publish')
            ->once()
            ->andThrow(new \RuntimeException('broker down'));

        $logSpy = Log::spy();

        $result = app(ProductSearchService::class)->search($this->searchParams(['q' => 'ok', 'sort' => 'relevance']));

        $this->assertSame(1, $result['products']['total']);
        $this->assertSame('Resilient', $result['products']['data'][0]['name']);

        $logSpy->shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message) {
                return $message === 'Kafka publish failed on search.performed';
            });
    }
}
