<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Catalog\Contracts\ProductFreshnessChecker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\SyncsDotEnvKeys;
use Tests\Fakes\PassthroughFreshnessChecker;

abstract class TestCase extends BaseTestCase
{
    use SyncsDotEnvKeys;

    protected function setUp(): void
    {
        // Empty GEMINI_API_KEY in container $_SERVER blocks Laravel from seeing
        // the bind-mounted .env value (same bug class as auth JWT / order Stripe).
        $this->syncDotEnvKeys(['GEMINI_API_KEY']);

        // PHPUnit force=true writes $_ENV only. Docker's CACHE_STORE=file stays
        // in $_SERVER, so Laravel keeps rate-limit hits in the shared file cache.
        putenv('CACHE_STORE=array');
        $_ENV['CACHE_STORE'] = 'array';
        $_SERVER['CACHE_STORE'] = 'array';

        parent::setUp();

        $this->app->instance(ProductFreshnessChecker::class, new PassthroughFreshnessChecker);
    }
}
