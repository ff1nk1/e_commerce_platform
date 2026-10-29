<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\IdempotencyStore;
use App\Kafka\Handlers\ProductEventsHandler;
use App\Messaging\ProductSnapshotReceived;
use App\Services\IdempotencyService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Shop\Messaging\InboundEnvelope;
use Tests\TestCase;

final class ProductSnapshotOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_order_create_fails_without_snapshot(): void
    {
        $this->bindScopedIdempotencyMock(expectPut: false);

        $this->expectException(ValidationException::class);

        resolve(OrderService::class)->create(1, 'a@example.com', 'A', [
            ['product_id' => 136, 'quantity' => 1],
        ], 'idem-missing-snapshot');
    }

    public function test_handler_snapshot_allows_order_create(): void
    {
        resolve(ProductEventsHandler::class)->onInbound(new ProductSnapshotReceived(new InboundEnvelope(
            eventType: 'product.created',
            messageId: 'test-product-created-136',
            topic: 'product.events',
            key: '136',
            sagaId: null,
            payload: [
                'event' => 'product.created',
                'timestamp' => now()->toIso8601String(),
                'data' => [
                    'id' => 136,
                    'name' => 'че то новое',
                    'price' => '6767.00',
                    'store_id' => 1,
                ],
            ],
            headers: ['event-type' => 'product.created'],
        )));

        $this->bindScopedIdempotencyMock(expectPut: true);

        $result = resolve(OrderService::class)->create(7, 'user7@example.com', 'User Seven', [
            ['product_id' => 136, 'quantity' => 2],
        ], 'idem-after-snapshot');

        $this->assertSame(201, $result['status']);
        $this->assertSame('6767.00', $result['body']['items'][0]['unit_price'] ?? null);
        $this->assertSame('13534.00', $result['body']['total_amount'] ?? null);
        $this->assertDatabaseHas('orders', [
            'user_id' => 7,
            'customer_email' => 'user7@example.com',
            'customer_name' => 'User Seven',
            'status' => 'pending',
            'total_amount' => '13534.00',
        ]);
        $this->assertDatabaseHas('order_items', [
            'product_id' => 136,
            'quantity' => 2,
            'unit_price' => '6767.00',
        ]);
    }

    public function test_http_order_create_uses_snapshot_price(): void
    {
        resolve(ProductEventsHandler::class)->onInbound(new ProductSnapshotReceived(new InboundEnvelope(
            eventType: 'product.updated',
            messageId: 'test-product-updated-137',
            topic: 'product.events',
            key: '137',
            sagaId: null,
            payload: [
                'event' => 'product.updated',
                'data' => [
                    'id' => 137,
                    'name' => 'f',
                    'price' => '111.00',
                    'store_id' => 1,
                ],
            ],
            headers: ['event-type' => 'product.updated'],
        )));

        $this->bindScopedIdempotencyMock(expectPut: true);

        $response = $this->postJson('/api/orders', [
            'user_id' => 3,
            'email' => 'user3@example.com',
            'name' => 'User Three',
            'items' => [
                ['product_id' => 137, 'quantity' => 1],
            ],
        ], [
            'Idempotency-Key' => 'http-idem-137',
        ]);

        $response->assertCreated()
            ->assertJsonPath('total_amount', '111.00');
    }

    private function bindScopedIdempotencyMock(bool $expectPut): void
    {
        $idempotency = Mockery::mock(IdempotencyService::class);
        $idempotency->shouldReceive('scopeKey')->andReturnUsing(
            fn (string $op, int $userId, string $key, ?int $resourceId = null): string => implode(':', array_filter(
                ['order', 'idempotency', $op, (string) $userId, $resourceId !== null ? (string) $resourceId : null, $key],
                static fn (?string $v): bool => $v !== null
            ))
        );
        $idempotency->shouldReceive('get')->andReturn(null);
        $idempotency->shouldReceive('acquireLock')->andReturn(true);
        $idempotency->shouldReceive('releaseLock')->once();
        if ($expectPut) {
            $idempotency->shouldReceive('put')->once();
        } else {
            $idempotency->shouldReceive('put')->never();
        }

        $this->app->instance(IdempotencyService::class, $idempotency);
        $this->app->instance(IdempotencyStore::class, $idempotency);
    }
}
