<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Docker compose injects DB_* into the process env; phpunit.xml force flags
        // are not always applied before Laravel boots. Force sqlite in-memory here.
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('DB_URL');
        putenv('DB_HOST');
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = ':memory:';
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
        $_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '';

        // auth_app often starts with JWT_SECRET="" in $_SERVER (empty .env at
        // container create). Laravel Env::get prefers $_SERVER over $_ENV, so
        // phpunit.xml force=true alone leaves config('jwt.secret') empty.
        $jwtSecret = (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');
        if ($jwtSecret === '') {
            $jwtSecret = 'test-secret-key-for-phpunit-only';
        }
        putenv('JWT_SECRET='.$jwtSecret);
        $_ENV['JWT_SECRET'] = $_SERVER['JWT_SECRET'] = $jwtSecret;

        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }
}
