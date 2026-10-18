<?php

namespace Tests\Unit;

use App\Services\SearchIndexService;
use Mockery;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;
use OpenSearch\Namespaces\IndicesNamespace;
use Tests\Concerns\CapturesOpenSearchRequests;
use Tests\TestCase;

class SearchIndexServiceTest extends TestCase
{
    use CapturesOpenSearchRequests;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_index_product_maps_nested_and_flat_fields(): void
    {
        $this->mockOpenSearchClient();

        app(SearchIndexService::class)->indexProduct([
            'id' => 10,
            'name' => 'Phone',
            'store_id' => 7,
            'price' => '199.99',
            'description' => 'Nice',
            'image_url' => 'http://img',
            'category' => ['id' => 3, 'name' => 'Gadgets'],
            'store' => ['id' => 7, 'name' => 'Main'],
            'quantity' => 4,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
                ['name' => '', 'value' => 'skip'],
                ['name' => 'Size', 'value' => ''],
                'invalid',
            ],
        ], preserveStock: false);

        $doc = $this->capturedIndexRequests[0]['body'];
        $this->assertSame(10, $doc['id']);
        $this->assertSame(7, $doc['store_id']);
        $this->assertSame(3, $doc['category_id']);
        $this->assertSame('Gadgets', $doc['category_name']);
        $this->assertSame('Main', $doc['store_name']);
        $this->assertSame(199.99, $doc['price']);
        $this->assertSame(4, $doc['quantity']);
        $this->assertTrue($doc['in_stock']);
        $this->assertSame([
            ['name' => 'Color', 'value' => 'Black'],
        ], $doc['attributes']);
    }

    public function test_index_product_preserves_stock_via_partial_update(): void
    {
        $client = $this->mockOpenSearchClient();
        $client->shouldReceive('exists')->once()->with(Mockery::on(function (array $params): bool {
            return $params['index'] === 'products' && $params['id'] === '10';
        }))->andReturn(true);

        $updateDoc = null;
        $client->shouldReceive('update')->once()->with(Mockery::on(function (array $params) use (&$updateDoc): bool {
            $updateDoc = $params['body']['doc'] ?? null;

            return $params['index'] === 'products' && $params['id'] === '10';
        }))->andReturn(['result' => 'updated']);

        app(SearchIndexService::class)->indexProduct([
            'id' => 10,
            'name' => 'Updated',
            'store_id' => 1,
            'quantity' => 0,
            'in_stock' => false,
        ], preserveStock: true);

        $this->assertSame([], $this->capturedIndexRequests);
        $this->assertIsArray($updateDoc);
        $this->assertSame('Updated', $updateDoc['name']);
        $this->assertArrayNotHasKey('quantity', $updateDoc);
        $this->assertArrayNotHasKey('in_stock', $updateDoc);
    }

    public function test_index_product_skips_invalid_id(): void
    {
        $this->mockOpenSearchClient();

        app(SearchIndexService::class)->indexProduct([
            'id' => 0,
            'name' => 'Nope',
            'store_id' => 1,
        ], preserveStock: false);

        $this->assertSame([], $this->capturedIndexRequests);
    }

    public function test_delete_product_swallows_missing_404(): void
    {
        $client = $this->mockOpenSearchClient();
        $client->shouldReceive('delete')->once()->andThrow(new Missing404Exception('not found'));

        app(SearchIndexService::class)->deleteProduct(99);
        $this->assertTrue(true);
    }

    public function test_index_category_document_shape(): void
    {
        $this->mockOpenSearchClient();

        app(SearchIndexService::class)->indexCategory([
            'id' => 5,
            'parent_id' => 1,
            'name' => 'Phones',
            'slug' => 'phones',
        ]);

        $req = $this->capturedIndexRequests[0];
        $this->assertSame('categories', $req['index']);
        $this->assertSame('5', $req['id']);
        $this->assertSame([
            'id' => 5,
            'parent_id' => 1,
            'name' => 'Phones',
            'slug' => 'phones',
        ], $req['body']);
    }

    public function test_delete_category_swallows_not_found_message(): void
    {
        $client = $this->mockOpenSearchClient();
        $client->shouldReceive('delete')->once()->andThrow(new \RuntimeException('index_not_found_exception 404'));

        app(SearchIndexService::class)->deleteCategory(5);
        $this->assertTrue(true);
    }

    public function test_sync_category_name_on_products_builds_update_by_query(): void
    {
        $client = $this->mockOpenSearchClient();
        $client->shouldReceive('updateByQuery')->once()->with(Mockery::on(function (array $params): bool {
            $this->assertSame('products', $params['index']);
            $this->assertTrue($params['refresh']);
            $this->assertSame('proceed', $params['conflicts']);
            $this->assertSame(['term' => ['category_id' => 3]], $params['body']['query']);
            $this->assertSame('ctx._source.category_name = params.category_name', $params['body']['script']['source']);
            $this->assertSame('New Name', $params['body']['script']['params']['category_name']);

            return true;
        }))->andReturn(['updated' => 2]);

        app(SearchIndexService::class)->syncCategoryNameOnProducts(3, 'New Name');
    }

    public function test_update_product_stock_skips_missing_product(): void
    {
        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->andReturn(true);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);
        $client->shouldReceive('exists')->once()->andReturn(false);
        $client->shouldNotReceive('update');
        $this->app->instance(Client::class, $client);

        app(SearchIndexService::class)->updateProductStock(44, 5);
    }

    public function test_update_product_stock_sets_in_stock_false_for_zero_qty(): void
    {
        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->andReturn(true);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);
        $client->shouldReceive('exists')->once()->andReturn(true);
        $client->shouldReceive('update')->once()->with(Mockery::on(function (array $params): bool {
            return $params['body']['doc'] === [
                'quantity' => 0,
                'in_stock' => false,
            ];
        }))->andReturn(['result' => 'updated']);
        $this->app->instance(Client::class, $client);

        app(SearchIndexService::class)->updateProductStock(44, 0);
    }

    public function test_ensure_indices_creates_missing_once(): void
    {
        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->twice()->andReturn(false, false);
        $indices->shouldReceive('create')->twice()->andReturn(['acknowledged' => true]);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);
        $this->app->instance(Client::class, $client);

        $service = app(SearchIndexService::class);
        $service->ensureIndices();
        $service->ensureIndices(); // second call must be no-op (in-process flag)
    }

    public function test_ensure_indices_skips_create_when_indices_exist(): void
    {
        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->twice()->andReturn(true, true);
        $indices->shouldNotReceive('create');

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);
        $this->app->instance(Client::class, $client);

        app(SearchIndexService::class)->ensureIndices();
    }

    public function test_non_array_attributes_normalize_to_empty(): void
    {
        $this->mockOpenSearchClient();

        app(SearchIndexService::class)->indexProduct([
            'id' => 1,
            'name' => 'X',
            'store_id' => 1,
            'attributes' => 'bad',
        ], preserveStock: false);

        $this->assertSame([], $this->capturedIndexRequests[0]['body']['attributes']);
    }
}
