<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Kafka\Handlers\ProductEventsHandler;
use App\Kafka\Handlers\StockEventsHandler;
use App\Messaging\CatalogStockChanged;
use App\Messaging\ProductLifecycle;
use App\Models\ProductCatalog;
use App\Services\Llm\Contracts\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\InboundEnvelope;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

final class ProductKafkaHandlersTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $this->llm);
    }

    public function test_product_created_upserts_and_embeds(): void
    {
        $message = $this->product(
            body: [
                'event' => 'product.created',
                'data' => [
                    'id' => 55,
                    'store_id' => 3,
                    'store' => ['id' => 3, 'name' => 'Gadget Hub'],
                    'name' => 'Phone',
                    'description' => 'Smart',
                    'price' => 199,
                    'category' => ['id' => 9, 'name' => 'Gadgets'],
                    'attributes' => [['name' => 'Color', 'value' => 'Black']],
                    'is_published' => true,
                ],
            ],
            headers: ['event-type' => 'product.created'],
            key: '55',
            topicName: 'product.created',
        );

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertDatabaseHas('product_catalog', [
            'product_id' => 55,
            'store_id' => 3,
            'store_name' => 'Gadget Hub',
            'name' => 'Phone',
            'category_name' => 'Gadgets',
            'is_published' => true,
        ]);

        $embedding = DB::table('product_catalog')->where('product_id', 55)->value('embedding');
        $this->assertNotNull($embedding);
        $this->assertSame(1, $this->llm->embedCalls);
    }

    public function test_product_created_unpublished_is_stored(): void
    {
        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: [
                'event' => 'product.created',
                'data' => [
                    'id' => 56,
                    'store_id' => 1,
                    'name' => 'Draft Phone',
                    'price' => 10,
                    'is_published' => false,
                ],
            ],
            headers: ['event-type' => 'product.created'],
        ));

        $this->assertDatabaseHas('product_catalog', [
            'product_id' => 56,
            'is_published' => false,
        ]);
        $this->assertNotNull(
            DB::table('product_catalog')->where('product_id', 56)->value('embedding')
        );
    }

    public function test_product_updated_changes_fields_and_reembeds(): void
    {
        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: [
                'event' => 'product.created',
                'data' => [
                    'id' => 60,
                    'store_id' => 1,
                    'name' => 'Old Name',
                    'price' => 10,
                    'attributes' => [['name' => 'Color', 'value' => 'Red']],
                    'is_published' => true,
                ],
            ],
            headers: ['event-type' => 'product.created'],
        ));
        $this->assertSame(1, $this->llm->embedCalls);

        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: [
                'event' => 'product.updated',
                'data' => [
                    'id' => 60,
                    'store_id' => 2,
                    'name' => 'New Name',
                    'price' => 99.5,
                    'attributes' => [['name' => 'Color', 'value' => 'Blue']],
                    'is_published' => true,
                ],
            ],
            headers: ['event-type' => 'product.updated'],
        ));

        $this->assertDatabaseHas('product_catalog', [
            'product_id' => 60,
            'store_id' => 2,
            'name' => 'New Name',
            'price' => 99.50,
        ]);
        $row = ProductCatalog::query()->where('product_id', 60)->first();
        $this->assertSame([['name' => 'Color', 'value' => 'Blue']], $row?->attributes);
        $this->assertSame(2, $this->llm->embedCalls);
    }

    public function test_product_published_upserts_new_product(): void
    {
        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: [
                'event' => 'product.published',
                'data' => [
                    'id' => 61,
                    'store_id' => 1,
                    'name' => 'Just Published',
                    'price' => 42,
                    'category' => ['id' => 3, 'name' => 'Books'],
                ],
            ],
            headers: ['event-type' => 'product.published'],
        ));

        $this->assertDatabaseHas('product_catalog', [
            'product_id' => 61,
            'name' => 'Just Published',
            'is_published' => true,
            'category_name' => 'Books',
        ]);
        $this->assertSame(1, $this->llm->embedCalls);
    }

    public function test_product_published_for_existing_without_name_only_sets_flag(): void
    {
        ProductCatalog::query()->create([
            'product_id' => 62,
            'store_id' => 1,
            'name' => 'Existing',
            'price' => 5,
            'is_published' => false,
            'in_stock' => true,
            'attributes' => [],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: [
                'event' => 'product.published',
                'data' => ['id' => 62],
            ],
            headers: ['event-type' => 'product.published'],
        ));

        $this->assertDatabaseHas('product_catalog', [
            'product_id' => 62,
            'name' => 'Existing',
            'is_published' => true,
        ]);
        $this->assertSame(1, $this->llm->embedCalls);
    }

    public function test_product_deleted_removes_row(): void
    {
        ProductCatalog::query()->create([
            'product_id' => 70,
            'store_id' => 1,
            'name' => 'Gone',
            'price' => 1,
            'is_published' => true,
            'in_stock' => true,
            'attributes' => [],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: ['event' => 'product.deleted', 'data' => ['id' => 70]],
            headers: ['event-type' => 'product.deleted'],
        ));

        $this->assertDatabaseMissing('product_catalog', ['product_id' => 70]);
    }

    public function test_product_unpublished_sets_flag(): void
    {
        ProductCatalog::query()->create([
            'product_id' => 71,
            'store_id' => 1,
            'name' => 'Hidden',
            'price' => 1,
            'is_published' => true,
            'in_stock' => true,
            'attributes' => [],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: ['event' => 'product.unpublished', 'data' => ['id' => 71, 'is_published' => false]],
            headers: ['event-type' => 'product.unpublished'],
        ));

        $this->assertDatabaseHas('product_catalog', [
            'product_id' => 71,
            'is_published' => false,
        ]);
    }

    public function test_unknown_event_type_is_ignored(): void
    {
        Log::spy();

        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: ['event' => 'product.something', 'data' => ['id' => 1]],
            headers: ['event-type' => 'product.something'],
        ));

        $this->assertSame(0, ProductCatalog::query()->count());
        $this->assertSame(0, $this->llm->embedCalls);
    }

    public function test_missing_data_does_not_throw(): void
    {
        Log::spy();

        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: ['event' => 'product.created'],
            headers: ['event-type' => 'product.created'],
        ));

        $this->assertSame(0, ProductCatalog::query()->count());
    }

    public function test_missing_id_does_not_throw(): void
    {
        Log::spy();

        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: ['event' => 'product.created', 'data' => ['name' => 'No Id']],
            headers: ['event-type' => 'product.created'],
        ));

        $this->assertSame(0, ProductCatalog::query()->count());
    }

    public function test_stock_changed_updates_in_stock(): void
    {
        ProductCatalog::query()->create([
            'product_id' => 80,
            'store_id' => 1,
            'name' => 'Stocked',
            'price' => 10,
            'is_published' => true,
            'in_stock' => true,
            'attributes' => [],
        ]);

        resolve(StockEventsHandler::class)->onInbound($this->stock(
            body: ['product_id' => 80, 'store_id' => 1, 'quantity' => 0],
            headers: ['event-type' => 'stock.changed'],
            topicName: 'catalog.events',
        ));

        $this->assertDatabaseHas('product_catalog', [
            'product_id' => 80,
            'in_stock' => false,
        ]);
    }

    public function test_product_updated_preserves_in_stock_when_stock_fields_absent(): void
    {
        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: [
                'event' => 'product.created',
                'data' => [
                    'id' => 81,
                    'store_id' => 1,
                    'name' => 'Shoes',
                    'price' => 50,
                    'is_published' => true,
                ],
            ],
            headers: ['event-type' => 'product.created'],
        ));

        resolve(StockEventsHandler::class)->onInbound($this->stock(
            body: ['product_id' => 81, 'store_id' => 1, 'quantity' => 0],
            headers: ['event-type' => 'stock.changed'],
        ));

        $this->assertDatabaseHas('product_catalog', [
            'product_id' => 81,
            'in_stock' => false,
        ]);

        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: [
                'event' => 'product.updated',
                'data' => [
                    'id' => 81,
                    'store_id' => 1,
                    'name' => 'Shoes v2',
                    'price' => 55,
                    'is_published' => true,
                ],
            ],
            headers: ['event-type' => 'product.updated'],
        ));

        $this->assertDatabaseHas('product_catalog', [
            'product_id' => 81,
            'name' => 'Shoes v2',
            'in_stock' => false,
        ]);
    }

    public function test_stock_changed_for_unknown_product_is_noop(): void
    {
        resolve(StockEventsHandler::class)->onInbound($this->stock(
            body: ['product_id' => 9999, 'store_id' => 1, 'quantity' => 5],
            headers: ['event-type' => 'stock.changed'],
            topicName: 'catalog.events',
        ));

        $this->assertSame(0, ProductCatalog::query()->count());
    }

    public function test_product_created_then_chat_returns_product(): void
    {
        resolve(ProductEventsHandler::class)->onInbound($this->product(
            body: [
                'event' => 'product.created',
                'data' => [
                    'id' => 100,
                    'store_id' => 1,
                    'name' => 'Blue sneakers',
                    'description' => 'Running shoes',
                    'price' => 120,
                    'category' => ['id' => 2, 'name' => 'Shoes'],
                    'attributes' => [['name' => 'Color', 'value' => 'Blue']],
                    'is_published' => true,
                ],
            ],
            headers: ['event-type' => 'product.created'],
        ));

        $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'Find blue sneakers',
            'limit' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('products.0.product_id', 100)
            ->assertJsonPath('products.0.path', '/products/100');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    private function product(array $body = [], array $headers = [], mixed $key = null, ?string $topicName = null): ProductLifecycle
    {
        return new ProductLifecycle($this->envelope($body, $headers, $key, $topicName ?? 'product.events', 'product'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    private function stock(array $body = [], array $headers = [], mixed $key = null, ?string $topicName = null): CatalogStockChanged
    {
        return new CatalogStockChanged($this->envelope($body, $headers, $key, $topicName ?? 'stock.events', 'stock'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    private function envelope(array $body, array $headers, mixed $key, string $topic, string $eventType): InboundEnvelope
    {
        $stringHeaders = [];
        foreach ($headers as $name => $value) {
            $stringHeaders[(string) $name] = is_scalar($value) ? (string) $value : '';
        }
        $type = $stringHeaders['event-type'] ?? (is_string($body['event'] ?? null) ? $body['event'] : $eventType);

        return new InboundEnvelope(
            eventType: $type,
            messageId: 'test-'.$type,
            topic: $topic,
            key: is_scalar($key) ? (string) $key : null,
            sagaId: null,
            payload: $body,
            headers: $stringHeaders,
        );
    }
}
