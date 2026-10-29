<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Kafka\Handlers\ProductEventsHandler;
use App\Messaging\ProductSnapshotReceived;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Shop\Messaging\InboundEnvelope;
use Tests\TestCase;

final class ProductEventsHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_product_created_inserts_snapshot(): void
    {
        $message = $this->consumerMessage('product.created', [
            'event' => 'product.created',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'id' => 42,
                'name' => 'Phone',
                'price' => '199.99',
                'store_id' => 7,
                'description' => 'ignored',
                'category' => ['id' => 3, 'name' => 'Gadgets', 'slug' => 'gadgets'],
                'store' => ['id' => 7, 'name' => 'Main'],
            ],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertDatabaseHas('product_snapshots', [
            'id' => 42,
            'name' => 'Phone',
            'price' => '199.99',
            'store_id' => 7,
            'store_name' => 'Main',
            'category_id' => 3,
            'category_name' => 'Gadgets',
            'category_slug' => 'gadgets',
        ]);
        $attrs = array_keys(ProductSnapshot::query()->find(42)->getAttributes());
        sort($attrs);
        $this->assertSame(
            ['category_id', 'category_name', 'category_slug', 'created_at', 'id', 'name', 'price', 'store_id', 'store_name', 'updated_at'],
            $attrs
        );
    }

    public function test_product_updated_overwrites_snapshot(): void
    {
        ProductSnapshot::query()->create([
            'id' => 42,
            'name' => 'Old phone',
            'price' => '100.00',
            'store_id' => 7,
        ]);

        $message = $this->consumerMessage('product.updated', [
            'event' => 'product.updated',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'id' => 42,
                'name' => 'New phone',
                'price' => '249.50',
                'store_id' => 9,
            ],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertDatabaseHas('product_snapshots', [
            'id' => 42,
            'name' => 'New phone',
            'price' => '249.50',
            'store_id' => 9,
        ]);
        $this->assertSame(1, ProductSnapshot::query()->count());
    }

    public function test_product_created_upserts_existing_snapshot(): void
    {
        ProductSnapshot::query()->create([
            'id' => 10,
            'name' => 'Before',
            'price' => '10.00',
            'store_id' => 1,
        ]);

        $message = $this->consumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => 10,
                'name' => 'After',
                'price' => '20.00',
                'store_id' => 2,
            ],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertDatabaseHas('product_snapshots', [
            'id' => 10,
            'name' => 'After',
            'price' => '20.00',
            'store_id' => 2,
        ]);
        $this->assertSame(1, ProductSnapshot::query()->count());
    }

    public function test_falls_back_to_body_event_when_header_missing(): void
    {
        $message = $this->consumerMessage(null, [
            'event' => 'product.created',
            'data' => [
                'id' => 5,
                'name' => 'From body event',
                'price' => '55.00',
                'store_id' => 1,
            ],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertDatabaseHas('product_snapshots', [
            'id' => 5,
            'name' => 'From body event',
            'price' => '55.00',
            'store_id' => 1,
        ]);
    }

    public function test_casts_string_ids_to_integers(): void
    {
        $message = $this->consumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => '88',
                'name' => 'Casted',
                'price' => 12.5,
                'store_id' => '3',
                'category_id' => '5',
            ],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $snapshot = ProductSnapshot::query()->find(88);
        $this->assertNotNull($snapshot);
        $this->assertSame(88, $snapshot->id);
        $this->assertSame(3, $snapshot->store_id);
        $this->assertSame(5, $snapshot->category_id);
        $this->assertSame('12.50', (string) $snapshot->price);
    }

    public function test_invalid_payload_is_skipped(): void
    {
        $message = $this->consumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => 1,
                'name' => 'Incomplete',
            ],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertSame(0, ProductSnapshot::query()->count());
    }

    public function test_missing_data_is_skipped(): void
    {
        $message = $this->consumerMessage('product.created', [
            'event' => 'product.created',
            'timestamp' => now()->toIso8601String(),
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertSame(0, ProductSnapshot::query()->count());
    }

    public function test_non_array_data_is_skipped(): void
    {
        $message = $this->consumerMessage('product.created', [
            'event' => 'product.created',
            'data' => 'not-an-array',
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertSame(0, ProductSnapshot::query()->count());
    }

    public function test_unknown_event_type_is_skipped(): void
    {
        $message = $this->consumerMessage('product.deleted', [
            'event' => 'product.deleted',
            'data' => [
                'id' => 1,
                'name' => 'Gone',
                'price' => '10.00',
                'store_id' => 1,
            ],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertSame(0, ProductSnapshot::query()->count());
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list', ['namespace' => 'kafka'])
            ->expectsOutputToContain('kafka:consume:product-events')
            ->assertSuccessful();
    }

    public function test_skips_upsert_when_product_snapshots_table_missing(): void
    {
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('product_snapshots')
            ->andReturn(false);

        $message = $this->consumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => 1,
                'name' => 'Phone',
                'price' => '10.00',
                'store_id' => 1,
            ],
        ]);

        resolve(ProductEventsHandler::class)->onInbound($message);

        $this->assertSame(0, ProductSnapshot::query()->count());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function consumerMessage(?string $eventType, array $body): ProductSnapshotReceived
    {
        $headers = $eventType === null ? [] : ['event-type' => $eventType];

        return new ProductSnapshotReceived(new InboundEnvelope(
            eventType: $eventType ?? '',
            messageId: 'test-'.($eventType ?? 'none'),
            topic: 'product.events',
            key: null,
            sagaId: null,
            payload: $body,
            headers: $headers,
        ));
    }
}
