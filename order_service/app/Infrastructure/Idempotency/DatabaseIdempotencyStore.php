<?php

declare(strict_types=1);

namespace App\Infrastructure\Idempotency;

use App\Contracts\IdempotencyStore;
use App\DTO\Orders\IdempotentResponse;
use App\Models\IdempotencyRecord;
use Illuminate\Support\Facades\Cache;

/**
 * DB-backed idempotency store.
 *
 * The stored response lives in the same database as the domain rows it
 * describes, so it can be written inside the domain transaction and can never
 * drift from Postgres. Redis is used only for a short-lived lock so two
 * concurrent identical requests do not both run the domain flow.
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    private const LOCK_TTL_SECONDS = 30;

    public function get(string $scopeKey): ?IdempotentResponse
    {
        $record = IdempotencyRecord::query()->where('scope_key', $scopeKey)->first();
        if (! $record instanceof IdempotencyRecord) {
            return null;
        }

        return IdempotentResponse::fromArray($record->response);
    }

    public function put(string $scopeKey, IdempotentResponse $response): void
    {
        // Atomic upsert (ON CONFLICT) so a concurrent retry cannot raise inside an
        // open DB transaction the way a caught unique-violation would.
        IdempotencyRecord::query()->upsert(
            [[
                'scope_key' => $scopeKey,
                'response' => json_encode($response->toArray(), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['scope_key'],
            ['response', 'updated_at'],
        );
    }

    public function acquireLock(string $scopeKey): bool
    {
        return Cache::add(
            $this->lockKey($scopeKey),
            '1',
            self::LOCK_TTL_SECONDS
        );
    }

    public function releaseLock(string $scopeKey): void
    {
        Cache::forget($this->lockKey($scopeKey));
    }

    public function scopeKey(string $operation, int $userId, string $clientKey, ?int $resourceId = null): string
    {
        $parts = ['order', 'idempotency', $operation, (string) $userId];
        if ($resourceId !== null) {
            $parts[] = (string) $resourceId;
        }
        $parts[] = $clientKey;

        return implode(':', $parts);
    }

    private function lockKey(string $scopeKey): string
    {
        return $scopeKey.':lock';
    }
}
