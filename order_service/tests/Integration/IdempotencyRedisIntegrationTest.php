<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\OutboxEvent;
use App\Services\IdempotencyService;
use Illuminate\Support\Str;

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

        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.created')->count());
        $this->assertDatabaseCount('orders', 1);

        $idem = resolve(IdempotencyService::class);
        $scopeKey = $idem->scopeKey('create', (int) $payload['user_id'], $clientKey);
        $cached = $idem->get($scopeKey);
        $this->assertSame(201, $cached['status'] ?? null);
        $this->assertSame($orderId, $cached['body']['id'] ?? null);
    }

    public function test_lock_contention_returns_conflict_when_response_not_cached_yet(): void
    {
        $clientKey = 'it-lock-'.Str::uuid();
        $idem = resolve(IdempotencyService::class);
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
