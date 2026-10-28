<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Kafka\Handlers\CategoryEventsHandler;
use App\Kafka\Handlers\ProductEventsHandler;
use App\Kafka\Handlers\StockEventsHandler;
use App\Services\ProductSearchService;
use OpenSearch\Client;

final class CatalogEventsIndexingIntegrationTest extends IntegrationTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function params(array $overrides = []): array
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

    public function test_product_created_event_indexes_document(): void
    {
        $message = $this->stubConsumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => 9001,
                'name' => 'Kafka Created Phone',
                'price' => 199.0,
                'store_id' => 7,
                'category_id' => 3,
                'category' => ['id' => 3, 'name' => 'Gadgets'],
                'store' => ['id' => 7, 'name' => 'Main'],
                'quantity' => 2,
                'in_stock' => true,
                'attributes' => [
                    ['name' => 'Color', 'value' => 'Black'],
                ],
            ],
        ], 'product.created');

        resolve(ProductEventsHandler::class)($message);

        $result = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'Kafka Created Phone',
            'sort' => 'relevance',
        ]));

        $this->assertSame(1, $result['products']['total']);
        $this->assertSame(9001, $result['products']['data'][0]['id']);
        $this->assertTrue($result['products']['data'][0]['in_stock']);
    }

    public function test_product_updated_preserves_stock_from_index(): void
    {
        resolve(ProductEventsHandler::class)($this->stubConsumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => 9002,
                'name' => 'Stock Preserve',
                'price' => 50.0,
                'store_id' => 1,
                'quantity' => 0,
                'in_stock' => false,
            ],
        ], 'product.created'));

        resolve(StockEventsHandler::class)($this->stubConsumerMessage('stock.changed', [
            'product_id' => 9002,
            'store_id' => 1,
            'quantity' => 8,
        ], 'catalog.events'));

        resolve(ProductEventsHandler::class)($this->stubConsumerMessage('product.updated', [
            'event' => 'product.updated',
            'data' => [
                'id' => 9002,
                'name' => 'Stock Preserve Renamed',
                'price' => 55.0,
                'store_id' => 1,
                'quantity' => 0,
                'in_stock' => false,
            ],
        ], 'product.updated'));

        $doc = resolve(Client::class)->get([
            'index' => config('opensearch.indices.products'),
            'id' => '9002',
        ]);

        $this->assertSame('Stock Preserve Renamed', $doc['_source']['name']);
        $this->assertSame(8, $doc['_source']['quantity']);
        $this->assertTrue($doc['_source']['in_stock']);
    }

    public function test_product_deleted_event_removes_document(): void
    {
        resolve(ProductEventsHandler::class)($this->stubConsumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => 9003,
                'name' => 'To Be Deleted Via Kafka',
                'price' => 10.0,
                'store_id' => 1,
            ],
        ], 'product.created'));

        resolve(ProductEventsHandler::class)($this->stubConsumerMessage('product.deleted', [
            'event' => 'product.deleted',
            'data' => ['id' => 9003],
        ], 'product.deleted'));

        $result = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'To Be Deleted Via Kafka',
            'sort' => 'relevance',
        ]));
        $this->assertSame(0, $result['products']['total']);
    }

    public function test_category_updated_syncs_name_on_products(): void
    {
        resolve(ProductEventsHandler::class)($this->stubConsumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => 9004,
                'name' => 'Category Event Product',
                'price' => 20.0,
                'store_id' => 1,
                'category_id' => 55,
                'category' => ['id' => 55, 'name' => 'Before'],
            ],
        ], 'product.created'));

        resolve(CategoryEventsHandler::class)($this->stubConsumerMessage('category.updated', [
            'event' => 'category.updated',
            'data' => [
                'id' => 55,
                'name' => 'After Sync',
                'slug' => 'after-sync',
            ],
        ], 'category.updated'));

        $doc = resolve(Client::class)->get([
            'index' => config('opensearch.indices.products'),
            'id' => '9004',
        ]);
        $this->assertSame('After Sync', $doc['_source']['category_name']);

        $cat = resolve(Client::class)->get([
            'index' => config('opensearch.indices.categories'),
            'id' => '55',
        ]);
        $this->assertSame('After Sync', $cat['_source']['name']);
    }

    public function test_stock_changed_event_updates_availability(): void
    {
        resolve(ProductEventsHandler::class)($this->stubConsumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => 9005,
                'name' => 'Stock Event Product',
                'price' => 30.0,
                'store_id' => 1,
                'quantity' => 4,
                'in_stock' => true,
            ],
        ], 'product.created'));

        resolve(StockEventsHandler::class)($this->stubConsumerMessage('stock.changed', [
            'product_id' => 9005,
            'store_id' => 1,
            'quantity' => 0,
        ], 'catalog.events'));

        $result = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'Stock Event Product',
            'in_stock' => false,
            'sort' => 'relevance',
        ]));

        $this->assertSame(1, $result['products']['total']);
        $this->assertFalse($result['products']['data'][0]['in_stock']);
        $this->assertSame(0, $result['products']['data'][0]['quantity']);
    }
}
