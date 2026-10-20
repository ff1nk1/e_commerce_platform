<?php

namespace Tests\Concerns;

use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use OpenSearch\Namespaces\IndicesNamespace;

trait CapturesOpenSearchRequests
{
    /** @var list<array<string, mixed>> */
    protected array $capturedSearchRequests = [];

    /** @var list<array<string, mixed>> */
    protected array $capturedIndexRequests = [];

    /**
     * @param  array<string, mixed>|null  $searchResponse
     */
    protected function mockOpenSearchClient(?array $searchResponse = null, bool $indicesExist = true): Client&MockInterface
    {
        $this->capturedSearchRequests = [];
        $this->capturedIndexRequests = [];

        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->andReturn($indicesExist);
        $indices->shouldReceive('create')->andReturn(['acknowledged' => true]);
        $indices->shouldReceive('delete')->andReturn(['acknowledged' => true]);
        $indices->shouldReceive('getAlias')->andThrow(new \RuntimeException('not an alias'));

        /** @var Client&MockInterface $client */
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);

        $client->shouldReceive('search')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (array $params) use ($searchResponse) {
                $this->capturedSearchRequests[] = $params;

                return $searchResponse ?? $this->emptySearchResponse();
            });

        $client->shouldReceive('index')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (array $params) {
                $this->capturedIndexRequests[] = $params;

                return ['result' => 'created'];
            });

        $client->shouldReceive('exists')->byDefault()->andReturn(false);
        $client->shouldReceive('get')->byDefault()->andReturn(['_source' => []]);
        $client->shouldReceive('delete')->byDefault()->andReturn(['result' => 'deleted']);
        $client->shouldReceive('update')->byDefault()->andReturn(['result' => 'updated']);
        $client->shouldReceive('updateByQuery')->byDefault()->andReturn(['updated' => 0]);

        $this->app->instance(Client::class, $client);

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptySearchResponse(array $aggregationOverrides = []): array
    {
        return [
            'hits' => [
                'total' => ['value' => 0],
                'hits' => [],
            ],
            'aggregations' => [
                'catalog_facets' => array_replace_recursive([
                    'categories' => ['buckets' => []],
                    'price_ranges' => [
                        'buckets' => [
                            '0-500' => ['doc_count' => 0],
                            '500-1000' => ['doc_count' => 0],
                            '1000-5000' => ['doc_count' => 0],
                            '5000+' => ['doc_count' => 0],
                        ],
                    ],
                    'availability' => ['buckets' => []],
                    'attributes' => ['names' => ['buckets' => []]],
                ], $aggregationOverrides),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{
     *     q: string|null,
     *     category_ids: list<int>,
     *     min_price: float|null,
     *     max_price: float|null,
     *     in_stock: bool|null,
     *     attributes: array<string, list<string>>,
     *     page: int,
     *     per_page: int,
     *     sort: string
     * }
     */
    protected function searchParams(array $overrides = []): array
    {
        return array_merge([
            'q' => null,
            'category_ids' => [],
            'min_price' => null,
            'max_price' => null,
            'in_stock' => null,
            'attributes' => [],
            'page' => 1,
            'per_page' => 20,
            'sort' => 'new',
        ], $overrides);
    }

    protected function lastSearchBody(): array
    {
        $this->assertNotEmpty($this->capturedSearchRequests, 'Expected at least one OpenSearch search call');

        return $this->capturedSearchRequests[array_key_last($this->capturedSearchRequests)]['body'] ?? [];
    }
}
