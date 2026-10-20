<?php

namespace Tests\Feature;

use App\Kafka\Handlers\OrderCreatedHandler;
use App\Kafka\Handlers\StockReleaseHandler;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Junges\Kafka\Contracts\ConsumerMessage;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\BindsInMemorySagaStepGuard;
use Tests\TestCase;

class OrderStockHandlersTest extends TestCase
{
    use BindsInMemorySagaStepGuard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInMemorySagaStepGuard();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_order_created_reserves_stock_and_writes_outbox(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        $message = $this->consumerMessage('order.created', [
            'order_id' => 1001,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 3,
            ]],
        ]);

        app(OrderCreatedHandler::class)($message);

        $fresh = $stock->fresh();
        $this->assertEquals(10, $fresh->quantity);
        $this->assertEquals(3, $fresh->reserved);
        $this->assertEquals(7, $fresh->available);
        $this->assertDatabaseHas('outbox_events', [
            'topic' => 'stock.events',
            'event_type' => 'stock.reserved',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'topic' => 'catalog.events',
            'event_type' => 'stock.changed',
        ]);
        $changed = OutboxEvent::where('event_type', 'stock.changed')->first();
        $this->assertSame(7, $changed->payload['quantity']);
    }

    public function test_order_created_fails_without_partial_stock_change(): void
    {
        $store = Store::factory()->create();
        $productA = Product::factory()->create(['store_id' => $store->id]);
        $productB = Product::factory()->create(['store_id' => $store->id]);
        $stockA = Stock::factory()->create([
            'product_id' => $productA->id,
            'store_id' => $store->id,
            'quantity' => 5,
            'reserved' => 0,
        ]);
        $stockB = Stock::factory()->create([
            'product_id' => $productB->id,
            'store_id' => $store->id,
            'quantity' => 1,
            'reserved' => 0,
        ]);

        $message = $this->consumerMessage('order.created', [
            'order_id' => 1002,
            'items' => [
                ['product_id' => $productA->id, 'store_id' => $store->id, 'quantity' => 2],
                ['product_id' => $productB->id, 'store_id' => $store->id, 'quantity' => 5],
            ],
        ]);

        app(OrderCreatedHandler::class)($message);

        $this->assertEquals(5, $stockA->fresh()->quantity);
        $this->assertEquals(0, $stockA->fresh()->reserved);
        $this->assertEquals(1, $stockB->fresh()->quantity);
        $this->assertEquals(0, $stockB->fresh()->reserved);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'stock.reservation_failed',
        ]);
        $this->assertSame(0, OutboxEvent::where('event_type', 'stock.reserved')->count());
    }

    public function test_stock_release_decrements_reserved(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 4,
        ]);

        $message = $this->consumerMessage('stock.release_requested', [
            'order_id' => 1003,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 2,
            ]],
        ]);

        app(StockReleaseHandler::class)($message);

        $fresh = $stock->fresh();
        $this->assertEquals(10, $fresh->quantity);
        $this->assertEquals(2, $fresh->reserved);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'stock.changed',
        ]);
        $changed = OutboxEvent::where('event_type', 'stock.changed')->first();
        $this->assertSame(8, $changed->payload['quantity']);
    }

    public function test_stock_release_skips_missing_stock_rows(): void
    {
        $message = $this->consumerMessage('stock.release_requested', [
            'order_id' => 1004,
            'items' => [[
                'product_id' => 99999,
                'store_id' => 99999,
                'quantity' => 1,
            ]],
        ]);

        app(StockReleaseHandler::class)($message);

        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_order_created_ignores_wrong_event_type(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        $message = $this->consumerMessage('other.event', [
            'order_id' => 1,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 1,
            ]],
        ]);

        app(OrderCreatedHandler::class)($message);

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertEquals(0, $stock->fresh()->reserved);
        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_order_created_invalid_payload_writes_no_outbox(): void
    {
        $message = $this->consumerMessage('order.created', [
            'order_id' => null,
            'items' => [],
        ]);

        app(OrderCreatedHandler::class)($message);

        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_order_created_rejects_non_positive_quantity(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        app(OrderCreatedHandler::class)($this->consumerMessage('order.created', [
            'order_id' => 1005,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 0,
            ]],
        ]));

        $this->assertEquals(0, $stock->fresh()->reserved);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'stock.reservation_failed',
        ]);
        $failed = OutboxEvent::where('event_type', 'stock.reservation_failed')->first();
        $this->assertSame('Invalid stock items', $failed->payload['reason']);
    }

    public function test_order_created_multi_item_success_writes_stock_changed_per_item(): void
    {
        $store = Store::factory()->create();
        $productA = Product::factory()->create(['store_id' => $store->id]);
        $productB = Product::factory()->create(['store_id' => $store->id]);
        $stockA = Stock::factory()->create([
            'product_id' => $productA->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);
        $stockB = Stock::factory()->create([
            'product_id' => $productB->id,
            'store_id' => $store->id,
            'quantity' => 8,
            'reserved' => 0,
        ]);

        $message = $this->consumerMessage('order.created', [
            'order_id' => 2001,
            'items' => [
                ['product_id' => $productA->id, 'store_id' => $store->id, 'quantity' => 2],
                ['product_id' => $productB->id, 'store_id' => $store->id, 'quantity' => 3],
            ],
        ]);

        app(OrderCreatedHandler::class)($message);

        $this->assertEquals(10, $stockA->fresh()->quantity);
        $this->assertEquals(2, $stockA->fresh()->reserved);
        $this->assertEquals(8, $stockB->fresh()->quantity);
        $this->assertEquals(3, $stockB->fresh()->reserved);

        $reserved = OutboxEvent::where('event_type', 'stock.reserved')->first();
        $this->assertNotNull($reserved);
        $this->assertSame([
            'order_id' => 2001,
            'status' => 'success',
        ], $reserved->payload);

        $changed = OutboxEvent::where('event_type', 'stock.changed')->orderBy('id')->get();
        $this->assertCount(2, $changed);
        $this->assertEqualsCanonicalizing(
            [
                ['product_id' => $productA->id, 'store_id' => $store->id, 'quantity' => 8],
                ['product_id' => $productB->id, 'store_id' => $store->id, 'quantity' => 5],
            ],
            $changed->pluck('payload')->all()
        );
    }

    public function test_order_created_missing_stock_row_fails_reservation(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);

        $message = $this->consumerMessage('order.created', [
            'order_id' => 2002,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 1,
            ]],
        ]);

        app(OrderCreatedHandler::class)($message);

        $failed = OutboxEvent::where('event_type', 'stock.reservation_failed')->first();
        $this->assertNotNull($failed);
        $this->assertSame([
            'order_id' => 2002,
            'reason' => 'Not enough stock',
        ], $failed->payload);
        $this->assertSame(0, OutboxEvent::where('event_type', 'stock.reserved')->count());
    }

    public function test_stock_release_invalid_payload_writes_no_outbox(): void
    {
        $message = $this->consumerMessage('stock.release_requested', [
            'order_id' => 2003,
            'items' => [],
        ]);

        app(StockReleaseHandler::class)($message);

        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_order_created_replay_with_same_saga_id_does_not_double_reserve(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);
        $sagaId = (string) Str::uuid();

        $message = $this->consumerMessage('order.created', [
            'saga_id' => $sagaId,
            'order_id' => 3001,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 3,
            ]],
        ]);

        app(OrderCreatedHandler::class)($message);
        app(OrderCreatedHandler::class)($message);

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertEquals(3, $stock->fresh()->reserved);
        $this->assertSame(1, OutboxEvent::where('event_type', 'stock.reserved')->count());
        $reserved = OutboxEvent::where('event_type', 'stock.reserved')->first();
        $this->assertSame($sagaId, $reserved->payload['saga_id']);
        $this->assertSame('done', $this->sagaStepStore[$sagaId.':stock.reserve']['status'] ?? null);
    }

    public function test_order_created_busy_lock_skips_second_worker(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);
        $sagaId = (string) Str::uuid();

        $this->sagaStepLocks[$sagaId.':stock.reserve'] = true;

        app(OrderCreatedHandler::class)($this->consumerMessage('order.created', [
            'saga_id' => $sagaId,
            'order_id' => 3002,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 2,
            ]],
        ]));

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertEquals(0, $stock->fresh()->reserved);
        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_stock_release_replay_with_same_saga_id_does_not_double_release(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 4,
        ]);
        $sagaId = (string) Str::uuid();

        $message = $this->consumerMessage('stock.release_requested', [
            'saga_id' => $sagaId,
            'order_id' => 3003,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 2,
            ]],
        ]);

        app(StockReleaseHandler::class)($message);
        app(StockReleaseHandler::class)($message);

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertEquals(2, $stock->fresh()->reserved);
        $this->assertSame(1, OutboxEvent::where('event_type', 'stock.changed')->count());
        $this->assertSame('done', $this->sagaStepStore[$sagaId.':stock.release']['status'] ?? null);
    }

    private function consumerMessage(string $eventType, array $body): ConsumerMessage&MockInterface
    {
        /** @var ConsumerMessage&MockInterface $message */
        $message = Mockery::mock(ConsumerMessage::class);
        $message->shouldReceive('getHeaders')->andReturn(['event-type' => $eventType]);
        $message->shouldReceive('getBody')->andReturn($body);

        return $message;
    }
}
