<?php

namespace App\Contracts;

interface SagaStepStore
{
    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array{status: string, result?: array<string, mixed>, at: string}|null
     */
    public function get(string $sagaId, string $step): ?array;

    public function isCompleted(string $sagaId, string $step): bool;

    public function tryBegin(string $sagaId, string $step): string;

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function put(string $sagaId, string $step, string $status, ?array $result = null): void;

    public function acquire(string $sagaId, string $step): bool;

    public function release(string $sagaId, string $step): void;
}
