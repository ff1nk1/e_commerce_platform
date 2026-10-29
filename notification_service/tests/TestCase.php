<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Compose injects Postgres into the process env. phpunit.xml force flags
        // are not always applied before Laravel boots, so pin sqlite here.
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('DB_URL');
        putenv('DB_HOST');
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = ':memory:';
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
        $_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '';

        parent::setUp();
    }
}
