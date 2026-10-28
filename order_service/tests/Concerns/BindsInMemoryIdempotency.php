<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Contracts\IdempotencyStore;
use App\Services\IdempotencyService;
use Mockery;

trait BindsInMemoryIdempotency
{
    /** @var array<string, array{status: int, body: array<string, mixed>}> */
    private array $idempotencyStore = [];

    protected function bindInMemoryIdempotency(): void
    {
        $store = &$this->idempotencyStore;

        $idem = Mockery::mock(IdempotencyService::class);
        $idem->shouldReceive('scopeKey')->andReturnUsing(
            function (string $operation, int $userId, string $clientKey, ?int $resourceId = null): string {
                $parts = ['order', 'idempotency', $operation, (string) $userId];
                if ($resourceId !== null) {
                    $parts[] = (string) $resourceId;
                }
                $parts[] = $clientKey;

                return implode(':', $parts);
            }
        );
        $idem->shouldReceive('get')->andReturnUsing(function (string $key) use (&$store) {
            return $store[$key] ?? null;
        });
        $idem->shouldReceive('acquireLock')->andReturn(true);
        $idem->shouldReceive('releaseLock')->andReturnNull();
        $idem->shouldReceive('put')->andReturnUsing(function (string $key, int $status, array $body) use (&$store): void {
            /** @var array<string, mixed> $body */
            $store[$key] = ['status' => $status, 'body' => $body];
        });

        $this->app->instance(IdempotencyService::class, $idem);
        $this->app->instance(IdempotencyStore::class, $idem);
    }
}
