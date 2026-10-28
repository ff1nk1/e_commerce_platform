<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\IdempotencyStore;
use Illuminate\Support\Facades\Cache;

class IdempotencyService implements IdempotencyStore
{
    private const TTL_SECONDS = 86400;

    private const LOCK_TTL_SECONDS = 30;

    public function get(string $scopeKey): ?array
    {
        $decoded = Cache::get($this->responseKey($scopeKey));

        return is_array($decoded) ? $decoded : null;
    }

    public function put(string $scopeKey, int $status, array $body): void
    {
        Cache::put(
            $this->responseKey($scopeKey),
            [
                'status' => $status,
                'body' => $body,
            ],
            self::TTL_SECONDS
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

    private function responseKey(string $scopeKey): string
    {
        return $scopeKey;
    }

    private function lockKey(string $scopeKey): string
    {
        return $scopeKey.':lock';
    }
}
