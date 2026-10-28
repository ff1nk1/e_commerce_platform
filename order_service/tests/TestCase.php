<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\SyncsDotEnvKeys;

abstract class TestCase extends BaseTestCase
{
    use SyncsDotEnvKeys;

    protected function setUp(): void
    {
        // Same class of bug as auth JWT: empty STRIPE_* in container $_SERVER
        // wins over values in the bind-mounted .env when Laravel boots.
        $this->syncDotEnvKeys(['STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET']);

        parent::setUp();
    }
}
