<?php

declare(strict_types=1);

namespace Tests\Concerns;

use PDO;
use Throwable;

trait UsesIntegrationDatabase
{
    /**
     * Create order_db_test if missing, before the Laravel app connects.
     */
    protected function ensurePostgresTestDatabaseExistsFromEnv(): void
    {
        $host = getenv('DB_HOST') ?: 'order_service_db';
        $port = getenv('DB_PORT') ?: '5432';
        $database = getenv('DB_DATABASE') ?: 'order_db_test';
        $username = getenv('DB_USERNAME') ?: 'laravel';
        $password = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : 'secret';

        try {
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=postgres', $host, $port),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
            $stmt->execute(['name' => $database]);
            if ($stmt->fetchColumn() === false) {
                $pdo->exec('CREATE DATABASE "'.str_replace('"', '""', $database).'"');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('PostgreSQL unavailable: '.$e->getMessage());
        }
    }
}
