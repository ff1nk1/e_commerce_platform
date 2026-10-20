<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

trait UsesRedis
{
    protected function enableRedis(): void
    {
        $host = $this->envString('REDIS_HOST', 'order_service_redis');
        $port = (int) $this->envString('REDIS_PORT', '6379');
        $db = (int) $this->envString('REDIS_DB', '15');

        config([
            'database.redis.client' => $this->envString('REDIS_CLIENT', 'predis'),
            'database.redis.default.host' => $host,
            'database.redis.default.port' => $port,
            'database.redis.default.database' => $db,
            'database.redis.default.password' => $this->envNullable('REDIS_PASSWORD'),
            'cache.default' => 'redis',
            'database.redis.cache.host' => $host,
            'database.redis.cache.port' => $port,
            'database.redis.cache.database' => $db,
        ]);

        Redis::purge();
        Cache::forgetDriver('redis');
        Cache::forgetDriver('array');

        try {
            Redis::connection()->ping();
            Redis::connection()->select($db);
            Redis::flushdb();
            Cache::flush();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis unavailable: '.$e->getMessage());
        }
    }

    private function envString(string $key, string $default = ''): string
    {
        return $this->envNullable($key, $default) ?? $default;
    }

    private function envNullable(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}
