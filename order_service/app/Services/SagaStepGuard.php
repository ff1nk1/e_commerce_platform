<?php

namespace App\Services;

use App\Contracts\SagaStepStore;
use Illuminate\Support\Facades\Redis;

class SagaStepGuard implements SagaStepStore
{
    private const TTL_SECONDS = 86400;

    private const LOCK_TTL_SECONDS = 30;

    public const STATUS_DONE = SagaStepStore::STATUS_DONE;

    public const STATUS_FAILED = SagaStepStore::STATUS_FAILED;

    public function get(string $sagaId, string $step): ?array
    {
        $raw = Redis::get($this->resultKey($sagaId, $step));

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

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

    public function put(string $sagaId, string $step, string $status, ?array $result = null): void
    {
        $payload = [
            'status' => $status,
            'at' => now()->toIso8601String(),
        ];

        if ($result !== null) {
            $payload['result'] = $result;
        }

        Redis::setex(
            $this->resultKey($sagaId, $step),
            self::TTL_SECONDS,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    public function acquire(string $sagaId, string $step): bool
    {
        return (bool) Redis::set(
            $this->lockKey($sagaId, $step),
            '1',
            'EX',
            self::LOCK_TTL_SECONDS,
            'NX'
        );
    }

    public function release(string $sagaId, string $step): void
    {
        Redis::del($this->lockKey($sagaId, $step));
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
