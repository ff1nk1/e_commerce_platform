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

        parent::setUp();

        config([
            'opensearch.refresh_on_write' => true,
            'opensearch.allow_skip_analytics' => true,
        ]);
    }
}
