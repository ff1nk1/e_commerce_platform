<?php

namespace App\Contracts;

interface IdempotencyStore
{
    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public function get(string $scopeKey): ?array;

    /**
     * @param  array<string, mixed>  $body
     */
    public function put(string $scopeKey, int $status, array $body): void;

    public function acquireLock(string $scopeKey): bool;

    public function releaseLock(string $scopeKey): void;

    public function scopeKey(string $operation, int $userId, string $clientKey, ?int $resourceId = null): string;
}
