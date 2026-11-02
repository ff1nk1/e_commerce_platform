<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Contracts\IdempotencyStore;
use App\DTO\Orders\IdempotentResponse;
use Mockery;

trait BindsInMemoryIdempotency
{
    /** @var array<string, IdempotentResponse> */
    private array $idempotencyStore = [];

    protected function bindInMemoryIdempotency(): void
    {
        $store = &$this->idempotencyStore;

        $idem = Mockery::mock(IdempotencyStore::class);
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
        $idem->shouldReceive('get')->andReturnUsing(function (string $key) use (&$store): ?IdempotentResponse {
            return $store[$key] ?? null;
        });
        $idem->shouldReceive('acquireLock')->andReturn(true);
        $idem->shouldReceive('releaseLock')->andReturnNull();
        $idem->shouldReceive('put')->andReturnUsing(function (string $key, IdempotentResponse $response) use (&$store): void {
            $store[$key] = $response;
        });

        $this->app->instance(IdempotencyStore::class, $idem);
    }
}
