<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends BaseTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        // Compose env_file keeps prod-like flags false; phpunit.xml force=true
        // is not always applied before artisan boots the app.
        putenv('OPENSEARCH_REFRESH_ON_WRITE=true');
        putenv('ALLOW_SKIP_ANALYTICS=true');
        $_ENV['OPENSEARCH_REFRESH_ON_WRITE'] = $_SERVER['OPENSEARCH_REFRESH_ON_WRITE'] = 'true';
        $_ENV['ALLOW_SKIP_ANALYTICS'] = $_SERVER['ALLOW_SKIP_ANALYTICS'] = 'true';

        // Compose injects Postgres. Tests stay on sqlite so they do not touch search_db.
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('DB_URL');
        putenv('DB_HOST');
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = ':memory:';
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
        $_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '';

        parent::setUp();

        config([
            'opensearch.refresh_on_write' => true,
            'opensearch.allow_skip_analytics' => true,
        ]);
    }
}
