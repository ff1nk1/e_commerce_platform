<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SearchIndexService;
use Junges\Kafka\Facades\Kafka;
use Mockery;
use OpenSearch\Client;
use OpenSearch\Namespaces\IndicesNamespace;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class SearchApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_search_returns_products_facets_and_publishes_event(): void
    {
        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->andReturn(true);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);
        $client->shouldReceive('search')->once()->with(Mockery::on(function (array $params): bool {
            $body = $params['body'] ?? [];
            $this->assertSame('products', $params['index']);
            $this->assertArrayHasKey('query', $body);
            $this->assertArrayHasKey('aggs', $body);
            $this->assertArrayHasKey('post_filter', $body);
            $this->assertSame(0, $body['from']);
            $this->assertSame(20, $body['size']);

            return true;
        }))->andReturn([
            'hits' => [
                'total' => ['value' => 1],
                'hits' => [
                    [
                        '_id' => '42',
                        '_source' => [
                            'id' => 42,
                            'name' => 'Phone',
                            'price' => 199.99,
                            'image_url' => null,
                            'category_id' => 3,
                            'category_name' => 'Gadgets',
                            'store_id' => 7,
                            'store_name' => 'Main',
                            'in_stock' => true,
                            'quantity' => 5,
                            'attributes' => [
                                ['name' => 'Color', 'value' => 'Black'],
                            ],
                        ],
                    ],
                ],
            ],
            'aggregations' => [
                'catalog_facets' => [
                    'categories' => [
                        'buckets' => [
                            [
                                'key' => 3,
                                'doc_count' => 10,
                                'name' => ['buckets' => [['key' => 'Gadgets']]],
                            ],
                            [
                                'key' => 4,
                                'doc_count' => 5,
                                'name' => ['buckets' => [['key' => 'Jackets']]],
                            ],
                        ],
                    ],
                    'price_ranges' => [
                        'buckets' => [
                            '0-500' => ['doc_count' => 1],
                            '500-1000' => ['doc_count' => 0],
                            '1000-5000' => ['doc_count' => 0],
                            '5000+' => ['doc_count' => 0],
                        ],
                    ],
                    'availability' => [
                        'buckets' => [
                            ['key' => 1, 'key_as_string' => 'true', 'doc_count' => 1],
                        ],
                    ],
                    'attributes' => [
                        'names' => [
                            'buckets' => [
                                [
                                    'key' => 'Color',
                                    'doc_count' => 1,
                                    'values' => [
                                        'buckets' => [
                                            ['key' => 'Black', 'doc_count' => 1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->app->instance(Client::class, $client);

        // Avoid real Kafka in HTTP test — fake via facade if package available
        if (class_exists(Kafka::class)) {
            Kafka::fake();
        }

        $response = $this->getJson('/api/search?q=phone&category_id=3&in_stock=1&attr[Color]=Black');

        $response->assertOk()
            ->assertJsonPath('products.total', 1)
            ->assertJsonPath('products.data.0.id', 42)
            ->assertJsonPath('products.data.0.name', 'Phone')
            ->assertJsonPath('facets.categories.0.id', 3)
            ->assertJsonPath('facets.categories.0.count', 10)
            ->assertJsonPath('facets.categories.1.id', 4)
            ->assertJsonPath('facets.attributes.Color.0.value', 'Black')
            ->assertJsonPath('applied.q', 'phone');

        if (class_exists(Kafka::class)) {
            OutboxQueue::flush();
            Kafka::assertPublishedOn('search.events', null, function ($message): bool {
                return ($message->getHeaders()['event-type'] ?? null) === 'search.performed'
                    && ($message->getBody()['data']['results_count'] ?? null) === 1
                    && ($message->getBody()['data']['query'] ?? null) === 'phone';
            });
        }
    }

    public function test_product_index_includes_attributes(): void
    {
        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->andReturn(true);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);
        $client->shouldReceive('exists')->andReturn(false);
        $client->shouldReceive('index')->once()->with(Mockery::on(function (array $params): bool {
            $this->assertSame([
                ['name' => 'Color', 'value' => 'Black'],
            ], $params['body']['attributes']);

            return true;
        }))->andReturn(['result' => 'created']);

        $this->app->instance(Client::class, $client);

        resolve(SearchIndexService::class)->indexProduct([
            'id' => 1,
            'name' => 'Phone',
            'store_id' => 1,
            'price' => 10,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ], preserveStock: false);
    }
}
