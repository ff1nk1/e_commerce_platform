<?php

declare(strict_types=1);

namespace App\Services\Auth\Tokens;

use Illuminate\Support\Facades\Redis;

/**
 * Redis storage for refresh tokens: one key per token hash (value = user id)
 * plus a per-user set of active hashes for mass revocation.
 */
final class RefreshTokenStore
{
    private const KEY_PREFIX = 'refresh_token:';

    private const USER_INDEX_PREFIX = 'user_refresh_tokens:';

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function save(string $hash, int $userId, int $ttl): void
    {
        Redis::setex($this->key($hash), $ttl, $userId);

        $indexKey = $this->userIndexKey($userId);
        Redis::sadd($indexKey, $hash);
        Redis::expire($indexKey, $ttl);
    }

    public function userId(string $hash): ?int
    {
        $userId = Redis::get($this->key($hash));

        return is_numeric($userId) ? (int) $userId : null;
    }

    /**
     * Atomically read-and-delete the token hash (Redis 6.2+ GETDEL).
     *
     * Returns the owning user id on the first call and null for every later
     * call, so concurrent refresh requests cannot rotate the same token twice.
     */
    public function consume(string $hash): ?int
    {
        $userId = Redis::command('GETDEL', [$this->key($hash)]);

        return is_numeric($userId) ? (int) $userId : null;
    }

    public function ttl(string $hash): int
    {
        return (int) Redis::ttl($this->key($hash));
    }

    public function delete(string $hash): void
    {
        Redis::del($this->key($hash));
    }

    public function forget(int $userId, string $hash): void
    {
        Redis::srem($this->userIndexKey($userId), $hash);
    }

    /**
     * @return list<string>
     */
    public function hashesFor(int $userId): array
    {
        $hashes = Redis::smembers($this->userIndexKey($userId));

        return is_array($hashes) ? array_map(strval(...), $hashes) : [];
    }

    public function deleteUserIndex(int $userId): void
    {
        Redis::del($this->userIndexKey($userId));
    }

    private function key(string $hash): string
    {
        return self::KEY_PREFIX.$hash;
    }

    private function userIndexKey(int $userId): string
    {
        return self::USER_INDEX_PREFIX.$userId;
    }
}
