<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class SagaStepGuard
{
    private const TTL_SECONDS = 86400;

    private const LOCK_TTL_SECONDS = 30;

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array{status: string, result?: array<string, mixed>, at: string}|null
     */
    public function get(string $sagaId, string $step): ?array
    {
        $decoded = Cache::get($this->resultKey($sagaId, $step));

        return is_array($decoded) ? $decoded : null;
    }

    public function isCompleted(string $sagaId, string $step): bool
    {
        $existing = $this->get($sagaId, $step);
        if ($existing === null) {
            return false;
        }

        return in_array($existing['status'] ?? null, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }

    /**
     * Attempt to claim a saga step. Returns "skip" if already completed,
     * "busy" if another worker holds the lock, "run" if this worker should proceed.
     */
    public function tryBegin(string $sagaId, string $step): string
    {
        if ($this->isCompleted($sagaId, $step)) {
            return 'skip';
        }

        if (! $this->acquire($sagaId, $step)) {
            return 'busy';
        }

        if ($this->isCompleted($sagaId, $step)) {
            $this->release($sagaId, $step);

            return 'skip';
        }

        return 'run';
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function put(string $sagaId, string $step, string $status, ?array $result = null): void
    {
        $payload = [
            'status' => $status,
            'at' => now()->toIso8601String(),
        ];

        if ($result !== null) {
            $payload['result'] = $result;
        }

        Cache::put(
            $this->resultKey($sagaId, $step),
            $payload,
            self::TTL_SECONDS
        );
    }

    public function acquire(string $sagaId, string $step): bool
    {
        return Cache::add(
            $this->lockKey($sagaId, $step),
            '1',
            self::LOCK_TTL_SECONDS
        );
    }

    public function release(string $sagaId, string $step): void
    {
        Cache::forget($this->lockKey($sagaId, $step));
    }

    private function resultKey(string $sagaId, string $step): string
    {
        return 'saga:'.$sagaId.':step:'.$step;
    }

    private function lockKey(string $sagaId, string $step): string
    {
        return 'saga:'.$sagaId.':lock:'.$step;
    }
}
