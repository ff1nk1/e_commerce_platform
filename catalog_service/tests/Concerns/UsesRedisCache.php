<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

trait UsesRedisCache
{
    protected function enableRedisCache(): void
    {
        $host = $this->envString('REDIS_HOST', '127.0.0.1');
        $port = $this->envString('REDIS_PORT', '6381');
        $db = $this->envString('REDIS_DB', '15');

        config([
            'cache.default' => 'redis',
            'database.redis.default.host' => $host,
            'database.redis.default.port' => $port,
            'database.redis.default.database' => $db,
            'database.redis.cache.host' => $host,
            'database.redis.cache.port' => $port,
            'database.redis.cache.database' => $db,
        ]);

        Cache::forgetDriver('redis');
        Redis::connection()->select((int) $db);
        Redis::flushdb();
        Cache::flush();
    }

    private function envString(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}
