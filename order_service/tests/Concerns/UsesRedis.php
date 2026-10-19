<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

trait UsesRedis
{
    protected function enableRedis(): void
    {
        $host = env('REDIS_HOST', 'order_service_redis');
        $port = (int) env('REDIS_PORT', 6379);
        $db = (int) env('REDIS_DB', 15);

        config([
            'database.redis.client' => env('REDIS_CLIENT', 'predis'),
            'database.redis.default.host' => $host,
            'database.redis.default.port' => $port,
            'database.redis.default.database' => $db,
            'database.redis.default.password' => env('REDIS_PASSWORD'),
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
}
