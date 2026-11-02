<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Orders\IdempotentResponse;

interface IdempotencyStore
{
    public function get(string $scopeKey): ?IdempotentResponse;

    public function put(string $scopeKey, IdempotentResponse $response): void;

    public function acquireLock(string $scopeKey): bool;

    public function releaseLock(string $scopeKey): void;

    public function scopeKey(string $operation, int $userId, string $clientKey, ?int $resourceId = null): string;
}
