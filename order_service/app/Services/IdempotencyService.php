<?php

namespace App\Services;

use App\Contracts\IdempotencyStore;
use Illuminate\Support\Facades\Redis;

class IdempotencyService implements IdempotencyStore
{
    private const TTL_SECONDS = 86400;

    private const LOCK_TTL_SECONDS = 30;

    public function get(string $scopeKey): ?array
    {
        $raw = Redis::get($this->responseKey($scopeKey));

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function put(string $scopeKey, int $status, array $body): void
    {
        Redis::setex(
            $this->responseKey($scopeKey),
            self::TTL_SECONDS,
            json_encode([
                'status' => $status,
                'body' => $body,
            ], JSON_THROW_ON_ERROR)
        );
    }

    public function acquireLock(string $scopeKey): bool
    {
        return (bool) Redis::set(
            $this->lockKey($scopeKey),
            '1',
            'EX',
            self::LOCK_TTL_SECONDS,
            'NX'
        );
    }

    public function releaseLock(string $scopeKey): void
    {
        Redis::del($this->lockKey($scopeKey));
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

    private function responseKey(string $scopeKey): string
    {
        return $scopeKey;
    }

    private function lockKey(string $scopeKey): string
    {
        return $scopeKey.':lock';
    }
}
