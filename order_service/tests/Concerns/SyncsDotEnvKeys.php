<?php

namespace Tests\Concerns;

/**
 * Docker may start the app with empty STRIPE_* in $_SERVER (env from an older
 * .env). Laravel Env prefers $_SERVER over the bind-mounted .env file, so we
 * re-apply non-empty values from the service .env before the app boots.
 */
trait SyncsDotEnvKeys
{
    /**
     * @param  list<string>  $keys
     */
    protected function syncDotEnvKeys(array $keys): void
    {
        $fromFile = $this->readDotEnvFile();

        foreach ($keys as $key) {
            $value = $this->nonEmptyEnv($key) ?? ($fromFile[$key] ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    private function nonEmptyEnv(string $key): ?string
    {
        foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $candidate) {
            if ($candidate === false || $candidate === null) {
                continue;
            }
            $value = trim((string) $candidate);
            if ($value !== '' && $value !== 'your_api_key_here' && ! str_contains($value, 'dummy')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function readDotEnvFile(): array
    {
        $path = dirname(__DIR__, 2).'/.env';
        if (! is_file($path)) {
            return [];
        }

        $parsed = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $parsed[$name] = trim($value, " \t\"'");
        }

        return $parsed;
    }
}
