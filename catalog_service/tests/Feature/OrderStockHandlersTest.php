<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\DuplicateStockReservationException;
use App\Exceptions\InsufficientStockReservationException;
use App\Exceptions\MissingStockReservationException;
use App\Exceptions\SagaStepBusyException;
use App\Kafka\Handlers\OrderCreatedHandler;
use App\Kafka\Handlers\StockCommitHandler;
use App\Kafka\Handlers\StockReleaseHandler;
use App\Messaging\HoldStockStep;
use App\Messaging\OrderCreatedMessage;
use App\Messaging\StockCommitRequested;
use App\Messaging\StockReleaseRequested;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockReservation;
use App\Models\Store;
use App\Services\StockReservationService;
use Ecotone\Modelling\EventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\OutboxQueue;
use Shop\Messaging\StoredOutboxMessage;
use Tests\TestCase;

final class OrderStockHandlersTest extends TestCase
{
    use RefreshDatabase;

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
            'saga_id' => (string) Str::uuid(),
            'order_id' => 1001,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 3,
            ]],
        ]);

        resolve(OrderCreatedHandler::class)->onInbound($message);

        $fresh = $stock->fresh();
        $this->assertEquals(10, $fresh->quantity);
        $this->assertEquals(3, $fresh->reserved);
        $this->assertEquals(7, $fresh->available);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => 1001,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 3,
            'status' => StockReservation::STATUS_HELD,
        ]);
        $stored = OutboxQueue::first('stock.reserved');
        $this->assertNotNull($stored);
        $this->assertSame('stock.events', $stored->topic);
        $stored = OutboxQueue::first('stock.changed');
        $this->assertNotNull($stored);
        $this->assertSame('catalog.events', $stored->topic);
        $this->assertSame(7, $stored->payload['quantity']);
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
            'saga_id' => (string) Str::uuid(),
            'order_id' => 1002,
            'items' => [
                ['product_id' => $productA->id, 'store_id' => $store->id, 'quantity' => 2],
                ['product_id' => $productB->id, 'store_id' => $store->id, 'quantity' => 5],
            ],
        ]);

        resolve(OrderCreatedHandler::class)->onInbound($message);

        $this->assertEquals(5, $stockA->fresh()->quantity);
        $this->assertEquals(0, $stockA->fresh()->reserved);
        $this->assertEquals(1, $stockB->fresh()->quantity);
        $this->assertEquals(0, $stockB->fresh()->reserved);
        $this->assertNotNull(OutboxQueue::first('stock.reservation_failed'));
        $this->assertSame(0, OutboxQueue::count('stock.reserved'));
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
        $this->holdForOrder(1003, $product->id, $store->id, 2);

        $message = $this->consumerMessage('stock.release_requested', [
            'saga_id' => (string) Str::uuid(),
            'order_id' => 1003,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 2,
            ]],
        ]);

        resolve(StockReleaseHandler::class)->onInbound($message);

        $fresh = $stock->fresh();
        $this->assertEquals(10, $fresh->quantity);
        $this->assertEquals(2, $fresh->reserved);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => 1003,
            'product_id' => $product->id,
            'status' => StockReservation::STATUS_RELEASED,
        ]);
        $changed = OutboxQueue::first('stock.changed');
        $this->assertNotNull($changed);
        $this->assertSame(8, $changed->payload['quantity']);
    }

    public function test_stock_release_missing_reservation_throws(): void
    {
        $message = $this->consumerMessage('stock.release_requested', [
            'saga_id' => (string) Str::uuid(),
            'order_id' => 1004,
            'items' => [[
                'product_id' => 99999,
                'store_id' => 99999,
                'quantity' => 1,
            ]],
        ]);

        try {
            resolve(StockReleaseHandler::class)->onInbound($message);
            $this->fail('Expected a missing reservation to throw.');
        } catch (MissingStockReservationException) {
        }

        $this->assertSame(0, OutboxQueue::count());
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

        resolve(OrderCreatedHandler::class)->onInbound($message);

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertEquals(0, $stock->fresh()->reserved);
        $this->assertSame(0, OutboxQueue::count());
    }

    public function test_order_created_invalid_payload_writes_no_outbox(): void
    {
        $message = $this->consumerMessage('order.created', [
            'order_id' => null,
            'items' => [],
        ]);

        resolve(OrderCreatedHandler::class)->onInbound($message);

        $this->assertSame(0, OutboxQueue::count());
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

        resolve(OrderCreatedHandler::class)->onInbound($this->consumerMessage('order.created', [
            'saga_id' => (string) Str::uuid(),
            'order_id' => 1005,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 0,
            ]],
        ]));

        $this->assertEquals(0, $stock->fresh()->reserved);
        $this->assertNotNull(OutboxQueue::first('stock.reservation_failed'));
        $failed = OutboxQueue::first('stock.reservation_failed');
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

        $sagaId = (string) Str::uuid();
        $message = $this->consumerMessage('order.created', [
            'saga_id' => $sagaId,
            'order_id' => 2001,
            'items' => [
                ['product_id' => $productA->id, 'store_id' => $store->id, 'quantity' => 2],
                ['product_id' => $productB->id, 'store_id' => $store->id, 'quantity' => 3],
            ],
        ]);

        resolve(OrderCreatedHandler::class)->onInbound($message);

        $this->assertEquals(10, $stockA->fresh()->quantity);
        $this->assertEquals(2, $stockA->fresh()->reserved);
        $this->assertEquals(8, $stockB->fresh()->quantity);
        $this->assertEquals(3, $stockB->fresh()->reserved);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => 2001,
            'product_id' => $productA->id,
            'quantity' => 2,
            'status' => StockReservation::STATUS_HELD,
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => 2001,
            'product_id' => $productB->id,
            'quantity' => 3,
            'status' => StockReservation::STATUS_HELD,
        ]);

        $reserved = OutboxQueue::first('stock.reserved');
        $this->assertNotNull($reserved);
        $this->assertSame([
            'order_id' => 2001,
            'status' => 'success',
            'saga_id' => $sagaId,
            'event_type' => 'stock.reserved',
            'event' => 'stock.reserved',
        ], $reserved->payload);

        $changed = OutboxQueue::ofType('stock.changed');
        $this->assertCount(2, $changed);
        $this->assertEqualsCanonicalizing(
            [
                ['product_id' => $productA->id, 'store_id' => $store->id, 'quantity' => 8, 'event_type' => 'stock.changed', 'event' => 'stock.changed'],
                ['product_id' => $productB->id, 'store_id' => $store->id, 'quantity' => 5, 'event_type' => 'stock.changed', 'event' => 'stock.changed'],
            ],
            array_map(static fn (StoredOutboxMessage $message): array => $message->payload, $changed)
        );
    }

    public function test_order_created_missing_stock_row_fails_reservation(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);

        $sagaId = (string) Str::uuid();
        $message = $this->consumerMessage('order.created', [
            'saga_id' => $sagaId,
            'order_id' => 2002,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 1,
            ]],
        ]);

        resolve(OrderCreatedHandler::class)->onInbound($message);

        $failed = OutboxQueue::first('stock.reservation_failed');
        $this->assertNotNull($failed);
        $this->assertSame([
            'order_id' => 2002,
            'reason' => 'Not enough stock',
            'saga_id' => $sagaId,
            'event_type' => 'stock.reservation_failed',
            'event' => 'stock.reservation_failed',
        ], $failed->payload);
        $this->assertSame(0, OutboxQueue::count('stock.reserved'));
    }

    public function test_stock_release_invalid_payload_writes_no_outbox(): void
    {
        $message = $this->consumerMessage('stock.release_requested', [
            'order_id' => 2003,
            'items' => [],
        ]);

        resolve(StockReleaseHandler::class)->onInbound($message);

        $this->assertSame(0, OutboxQueue::count());
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

        resolve(OrderCreatedHandler::class)->onInbound($message);
        resolve(OrderCreatedHandler::class)->onInbound($message);

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertEquals(3, $stock->fresh()->reserved);
        $this->assertSame(1, OutboxQueue::count('stock.reserved'));
        $reserved = OutboxQueue::first('stock.reserved');
        $this->assertNotNull($reserved);
        $this->assertSame($sagaId, $reserved->payload['saga_id']);
        $this->assertSame('done', $this->stepStatus($sagaId, 'stock.reserve'));
    }

    public function test_order_created_busy_lock_throws_so_message_is_retried(): void
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

        resolve(EventBus::class)->publish(new HoldStockStep($sagaId, 'stock.reserve'));

        try {
            resolve(OrderCreatedHandler::class)->onInbound($this->consumerMessage('order.created', [
                'saga_id' => $sagaId,
                'order_id' => 3002,
                'items' => [[
                    'product_id' => $product->id,
                    'store_id' => $store->id,
                    'quantity' => 2,
                ]],
            ]));
            $this->fail('Expected SagaStepBusyException');
        } catch (SagaStepBusyException) {
            $this->assertEquals(10, $stock->fresh()->quantity);
            $this->assertEquals(0, $stock->fresh()->reserved);
            $this->assertSame(0, OutboxQueue::count());
        }
    }

    public function test_stock_commit_decreases_quantity_and_reserved(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 3,
        ]);
        $this->holdForOrder(4001, $product->id, $store->id, 3);
        $sagaId = (string) Str::uuid();

        resolve(StockCommitHandler::class)->onInbound($this->consumerMessage('stock.commit_requested', [
            'saga_id' => $sagaId,
            'order_id' => 4001,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 3,
            ]],
        ]));

        $fresh = $stock->fresh();
        $this->assertEquals(7, $fresh->quantity);
        $this->assertEquals(0, $fresh->reserved);
        $this->assertEquals(7, $fresh->available);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => 4001,
            'status' => StockReservation::STATUS_COMMITTED,
            'quantity' => 3,
        ]);
        $stored = OutboxQueue::first('stock.changed');
        $this->assertNotNull($stored);
        $this->assertSame('catalog.events', $stored->topic);
        $this->assertSame(7, $stored->payload['quantity']);
        $this->assertSame('done', $this->stepStatus($sagaId, 'stock.commit'));

        resolve(StockCommitHandler::class)->onInbound($this->consumerMessage('stock.commit_requested', [
            'saga_id' => $sagaId,
            'order_id' => 4001,
            'items' => [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 3,
            ]],
        ]));

        $this->assertEquals(7, $stock->fresh()->quantity);
        $this->assertEquals(0, $stock->fresh()->reserved);
        $this->assertSame(1, OutboxQueue::count('stock.changed'));
    }

    public function test_order_created_merges_duplicate_line_items_before_reserve(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        resolve(OrderCreatedHandler::class)->onInbound($this->consumerMessage('order.created', [
            'saga_id' => (string) Str::uuid(),
            'order_id' => 4002,
            'items' => [
                ['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 6],
                ['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 6],
            ],
        ]));

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertEquals(0, $stock->fresh()->reserved);
        $stored = OutboxQueue::first('stock.reservation_failed');
        $this->assertNotNull($stored);
        $this->assertSame('stock.events', $stored->topic);
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
        $this->holdForOrder(3003, $product->id, $store->id, 2);
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

        resolve(StockReleaseHandler::class)->onInbound($message);
        resolve(StockReleaseHandler::class)->onInbound($message);

        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertEquals(2, $stock->fresh()->reserved);
        $this->assertSame(1, OutboxQueue::count('stock.changed'));
        $this->assertSame('done', $this->stepStatus($sagaId, 'stock.release'));
    }

    public function test_release_then_commit_does_not_decrement_quantity(): void
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
        $items = [[
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 3,
        ]];

        resolve(OrderCreatedHandler::class)->onInbound($this->consumerMessage('order.created', [
            'saga_id' => $sagaId,
            'order_id' => 5001,
            'items' => $items,
        ]));
        $this->assertEquals(3, $stock->fresh()->reserved);

        resolve(StockReleaseHandler::class)->onInbound($this->consumerMessage('stock.release_requested', [
            'saga_id' => $sagaId,
            'order_id' => 5001,
            'items' => $items,
        ]));
        $this->assertEquals(0, $stock->fresh()->reserved);

        resolve(StockCommitHandler::class)->onInbound($this->consumerMessage('stock.commit_requested', [
            'saga_id' => $sagaId,
            'order_id' => 5001,
            'items' => $items,
        ]));

        $fresh = $stock->fresh();
        $this->assertEquals(10, $fresh->quantity);
        $this->assertEquals(0, $fresh->reserved);
        $this->assertSame('done', $this->stepStatus($sagaId, 'stock.commit'));
        $this->assertSame('skipped_after_release', $this->stepResult($sagaId, 'stock.commit')['outcome'] ?? null);
    }

    public function test_commit_then_release_is_noop_for_release(): void
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
        $items = [[
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 3,
        ]];

        resolve(OrderCreatedHandler::class)->onInbound($this->consumerMessage('order.created', [
            'saga_id' => $sagaId,
            'order_id' => 5002,
            'items' => $items,
        ]));

        resolve(StockCommitHandler::class)->onInbound($this->consumerMessage('stock.commit_requested', [
            'saga_id' => $sagaId,
            'order_id' => 5002,
            'items' => $items,
        ]));

        $afterCommit = $stock->fresh();
        $this->assertEquals(7, $afterCommit->quantity);
        $this->assertEquals(0, $afterCommit->reserved);
        $changedAfterCommit = OutboxQueue::count('stock.changed');

        resolve(StockReleaseHandler::class)->onInbound($this->consumerMessage('stock.release_requested', [
            'saga_id' => $sagaId,
            'order_id' => 5002,
            'items' => $items,
        ]));

        $fresh = $stock->fresh();
        $this->assertEquals(7, $fresh->quantity);
        $this->assertEquals(0, $fresh->reserved);
        $this->assertSame($changedAfterCommit, OutboxQueue::count('stock.changed'));
        $this->assertSame('done', $this->stepStatus($sagaId, 'stock.release'));
        $this->assertSame('skipped_after_commit', $this->stepResult($sagaId, 'stock.release')['outcome'] ?? null);
    }

    public function test_commit_without_reservation_throws_and_leaves_quantity(): void
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

        try {
            resolve(StockCommitHandler::class)->onInbound($this->consumerMessage('stock.commit_requested', [
                'saga_id' => $sagaId,
                'order_id' => 5003,
                'items' => [[
                    'product_id' => $product->id,
                    'store_id' => $store->id,
                    'quantity' => 3,
                ]],
            ]));
            $this->fail('Expected a missing reservation to throw.');
        } catch (MissingStockReservationException) {
        }

        $fresh = $stock->fresh();
        $this->assertEquals(10, $fresh->quantity);
        $this->assertEquals(0, $fresh->reserved);
        $this->assertSame(0, OutboxQueue::count('stock.changed'));
        $this->assertNull($this->stepStatus($sagaId, 'stock.commit'));
    }

    public function test_commit_above_held_quantity_throws(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 1,
        ]);
        $this->holdForOrder(5005, $product->id, $store->id, 1);

        try {
            resolve(StockCommitHandler::class)->onInbound($this->consumerMessage('stock.commit_requested', [
                'saga_id' => (string) Str::uuid(),
                'order_id' => 5005,
                'items' => [[
                    'product_id' => $product->id,
                    'store_id' => $store->id,
                    'quantity' => 3,
                ]],
            ]));
            $this->fail('Expected an under-held reservation to throw.');
        } catch (InsufficientStockReservationException) {
        }

        $fresh = $stock->fresh();
        throw_unless($fresh instanceof Stock, \RuntimeException::class, 'Stock row disappeared.');
        $this->assertEquals(10, $fresh->quantity);
        $this->assertEquals(1, $fresh->reserved);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => 5005,
            'status' => StockReservation::STATUS_HELD,
            'quantity' => 1,
        ]);
    }

    public function test_second_reserve_for_same_order_line_throws(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);
        $items = [[
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 2,
        ]];
        $service = resolve(StockReservationService::class);
        $service->reserve(42, $items, (string) Str::uuid());

        try {
            $service->reserve(42, $items, (string) Str::uuid());
            $this->fail('Expected a duplicate reservation to throw.');
        } catch (DuplicateStockReservationException) {
        }

        $this->assertSame(1, StockReservation::query()->where('order_id', 42)->count());
        $this->assertEquals(2, Stock::query()->where('product_id', $product->id)->value('reserved'));
    }

    public function test_handlers_without_saga_id_do_not_mutate_stock(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $stock = Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'reserved' => 2,
        ]);
        $items = [[
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 2,
        ]];

        resolve(OrderCreatedHandler::class)->onInbound($this->consumerMessage('order.created', [
            'order_id' => 5004,
            'items' => $items,
        ]));
        resolve(StockCommitHandler::class)->onInbound($this->consumerMessage('stock.commit_requested', [
            'order_id' => 5004,
            'items' => $items,
        ]));
        resolve(StockReleaseHandler::class)->onInbound($this->consumerMessage('stock.release_requested', [
            'order_id' => 5004,
            'items' => $items,
        ]));

        $fresh = $stock->fresh();
        $this->assertEquals(10, $fresh->quantity);
        $this->assertEquals(2, $fresh->reserved);
        $this->assertSame(0, OutboxQueue::count('stock.reserved'));
        $this->assertSame(0, OutboxQueue::count('stock.changed'));
    }

    public function test_reserve_inside_raw_pdo_transaction_increments_reserved(): void
    {
        $connection = DB::connection();
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $pdo = $connection->getPdo();
        $pdo->beginTransaction();

        try {
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertTrue($pdo->inTransaction());

            $store = Store::factory()->create();
            $product = Product::factory()->create(['store_id' => $store->id]);
            $stock = Stock::factory()->create([
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 10,
                'reserved' => 0,
            ]);

            $result = resolve(StockReservationService::class)->reserve(42, [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 4,
            ]], (string) Str::uuid());

            $this->assertSame('reserved', $result['outcome']);
            $fresh = $stock->fresh();
            $this->assertNotNull($fresh);
            $this->assertEquals(10, $fresh->quantity);
            $this->assertEquals(4, $fresh->reserved);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function holdForOrder(int $orderId, int $productId, int $storeId, int $quantity): void
    {
        StockReservation::query()->create([
            'order_id' => $orderId,
            'product_id' => $productId,
            'store_id' => $storeId,
            'quantity' => $quantity,
            'status' => StockReservation::STATUS_HELD,
        ]);
    }

    private function stepStatus(string $sagaId, string $step): ?string
    {
        $status = $this->stepDocument($sagaId)['steps'][$step]['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepResult(string $sagaId, string $step): array
    {
        $result = $this->stepDocument($sagaId)['steps'][$step]['result'] ?? null;

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepDocument(string $sagaId): array
    {
        if (! Schema::hasTable('ecotone_document_store')) {
            return [];
        }

        $document = DB::table('ecotone_document_store')->where('document_id', $sagaId)->value('document');
        if (! is_string($document) || $document === '') {
            return [];
        }

        $decoded = json_decode($document, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return (
     *     $eventType is 'stock.commit_requested' ? StockCommitRequested : (
     *         $eventType is 'stock.release_requested' ? StockReleaseRequested : OrderCreatedMessage
     *     )
     * )
     */
    private function consumerMessage(string $eventType, array $body): OrderCreatedMessage|StockCommitRequested|StockReleaseRequested
    {
        $sagaId = $body['saga_id'] ?? null;
        $envelope = new InboundEnvelope(
            eventType: $eventType,
            messageId: 'test-'.$eventType,
            topic: 'order.events',
            key: isset($body['order_id']) && is_scalar($body['order_id']) ? (string) $body['order_id'] : null,
            sagaId: is_string($sagaId) && $sagaId !== '' ? $sagaId : null,
            payload: $body,
            headers: ['event-type' => $eventType],
        );

        return match ($eventType) {
            'stock.commit_requested' => new StockCommitRequested($envelope),
            'stock.release_requested' => new StockReleaseRequested($envelope),
            default => new OrderCreatedMessage($envelope),
        };
    }
}
