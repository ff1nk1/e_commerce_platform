<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\Concerns\UsesIntegrationDatabase;
use Tests\Concerns\UsesKafka;
use Tests\Concerns\UsesRedis;
use Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    use CreatesOrderFixtures;
    use RefreshDatabase;
    use UsesIntegrationDatabase;
    use UsesKafka;
    use UsesRedis;

    /**
     * Do not wrap tests in a DB transaction — saga handlers / outbox must see committed rows.
     *
     * @var array<int, string>
     */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        $this->ensurePostgresTestDatabaseExistsFromEnv();
        parent::setUp();
        $this->enableRedis();
    }

    protected function refreshTestDatabase(): void
    {
        // migrate:fresh every test so state is clean without transactional fixtures.
        $this->artisan('migrate:fresh', [
            '--force' => true,
        ]);

        RefreshDatabaseState::$migrated = true;
    }
}
