<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Idempotency\DatabaseIdempotencyStore;
use App\Models\IdempotencyRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Shop\Messaging\OutboxQueue;

final class IdempotencyRedisIntegrationTest extends IntegrationTestCase
{
    public function test_duplicate_create_with_same_idempotency_key_writes_single_order(): void
    {
        $this->seedSnapshot();

        $clientKey = 'it-create-'.Str::uuid();
        $headers = ['Idempotency-Key' => $clientKey];
        $payload = $this->orderCreatePayload();

        $first = $this->postJson('/api/orders', $payload, $headers);
        $first->assertCreated();
        $orderId = $first->json('id');

        $second = $this->postJson('/api/orders', $payload, $headers);
        $second->assertCreated();
        $this->assertSame($orderId, $second->json('id'));

        $this->assertSame(1, OutboxQueue::count('order.created'));
        $this->assertDatabaseCount('orders', 1);

        // Response is persisted in Postgres (not Redis) in the domain transaction.
        $idem = resolve(DatabaseIdempotencyStore::class);
        $scopeKey = $idem->scopeKey('create', (int) $payload['user_id'], $clientKey);
        $this->assertDatabaseHas('idempotency_keys', ['scope_key' => $scopeKey]);
        $record = IdempotencyRecord::query()->where('scope_key', $scopeKey)->first();
        $this->assertNotNull($record);
        $this->assertSame(201, $record->response['status'] ?? null);
        $this->assertSame($orderId, $record->response['body']['id'] ?? null);
    }

    public function test_cached_response_survives_redis_flush(): void
    {
        $this->seedSnapshot();

        $clientKey = 'it-durable-'.Str::uuid();
        $headers = ['Idempotency-Key' => $clientKey];
        $payload = $this->orderCreatePayload();

        $first = $this->postJson('/api/orders', $payload, $headers);
        $first->assertCreated();
        $orderId = $first->json('id');

        // Simulate Redis loss: idempotency must still be answered from Postgres.
        Cache::flush();

        $second = $this->postJson('/api/orders', $payload, $headers);
        $second->assertCreated();
        $this->assertSame($orderId, $second->json('id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_lock_contention_returns_conflict_when_response_not_cached_yet(): void
    {
        $clientKey = 'it-lock-'.Str::uuid();
        $idem = resolve(DatabaseIdempotencyStore::class);
        $scopeKey = $idem->scopeKey('create', 42, $clientKey);

        $this->assertTrue($idem->acquireLock($scopeKey));

        $this->seedSnapshot();
        $response = $this->postJson('/api/orders', $this->orderCreatePayload(), [
            'Idempotency-Key' => $clientKey,
        ]);

        // Lock held, no cached body → 409 after short wait in CreateOrderService.
        $response->assertConflict();

        $idem->releaseLock($scopeKey);
    }
}
