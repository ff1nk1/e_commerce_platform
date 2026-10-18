<?php

namespace Tests;

use App\Services\Catalog\Contracts\ProductFreshnessChecker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\PassthroughFreshnessChecker;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProductFreshnessChecker::class, new PassthroughFreshnessChecker);
    }
}
