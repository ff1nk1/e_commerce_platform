<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Request;

trait UsesRedisCache
{
    protected function enableRedisCache(): void
    {
        $host = $this->envString('REDIS_HOST', '127.0.0.1');
        $port = $this->envString('REDIS_PORT', '6381');
        $db = $this->envString('REDIS_DB', '15');
        // Redis runs with --requirepass, so tests must authenticate too.
        $password = $this->envString('REDIS_PASSWORD', 'redis_local_secret');

        config([
            'cache.default' => 'redis',
            'database.redis.default.host' => $host,
            'database.redis.default.port' => $port,
            'database.redis.default.database' => $db,
            'database.redis.default.password' => $password,
            'database.redis.cache.host' => $host,
            'database.redis.cache.port' => $port,
            'database.redis.cache.database' => $db,
            'database.redis.cache.password' => $password,
        ]);

        Cache::forgetDriver('redis');
        Redis::connection()->select((int) $db);
        Redis::flushdb();
        Cache::flush();
    }

    private function envString(string $key, string $default = ''): string
    {
        $value = Env::get($key, Request::server($key) ?? getenv($key));

        // Env::get() maps the literal "null" to null, which must not become "".
        if (in_array($value, [false, null, ''], true)) {
            return $default;
        }

        return (string) $value;
    }
}
