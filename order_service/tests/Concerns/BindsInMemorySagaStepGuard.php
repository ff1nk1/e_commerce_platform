<?php

namespace Tests\Concerns;

use App\Contracts\SagaStepStore;
use App\Services\SagaStepGuard;
use Mockery;
use Mockery\MockInterface;

trait BindsInMemorySagaStepGuard
{
    /** @var array<string, array{status: string, result?: array<string, mixed>, at: string}> */
    private array $sagaStepStore = [];

    /** @var array<string, bool> */
    private array $sagaStepLocks = [];

    protected function bindInMemorySagaStepGuard(): void
    {
        $store = &$this->sagaStepStore;
        $locks = &$this->sagaStepLocks;

        /** @var MockInterface&SagaStepGuard $guard */
        $guard = Mockery::mock(SagaStepGuard::class);

        $guard->shouldReceive('get')->andReturnUsing(function (string $sagaId, string $step) use (&$store) {
            return $store[$sagaId.':'.$step] ?? null;
        });

        $guard->shouldReceive('isCompleted')->andReturnUsing(function (string $sagaId, string $step) use (&$store) {
            $existing = $store[$sagaId.':'.$step] ?? null;
            if ($existing === null) {
                return false;
            }

            return in_array($existing['status'], [SagaStepGuard::STATUS_DONE, SagaStepGuard::STATUS_FAILED], true);
        });

        $guard->shouldReceive('acquire')->andReturnUsing(function (string $sagaId, string $step) use (&$locks) {
            $key = $sagaId.':'.$step;
            if (isset($locks[$key])) {
                return false;
            }
            $locks[$key] = true;

            return true;
        });

        $guard->shouldReceive('release')->andReturnUsing(function (string $sagaId, string $step) use (&$locks) {
            unset($locks[$sagaId.':'.$step]);
        });

        $guard->shouldReceive('put')->andReturnUsing(function (string $sagaId, string $step, string $status, ?array $result = null) use (&$store) {
            $payload = [
                'status' => $status,
                'at' => now()->toIso8601String(),
            ];
            if ($result !== null) {
                $payload['result'] = $result;
            }
            $store[$sagaId.':'.$step] = $payload;
        });

        $guard->shouldReceive('tryBegin')->andReturnUsing(function (string $sagaId, string $step) use ($guard) {
            if ($guard->isCompleted($sagaId, $step)) {
                return 'skip';
            }
            if (! $guard->acquire($sagaId, $step)) {
                return 'busy';
            }
            if ($guard->isCompleted($sagaId, $step)) {
                $guard->release($sagaId, $step);

                return 'skip';
            }

            return 'run';
        });

        $this->app->instance(SagaStepGuard::class, $guard);
        $this->app->instance(SagaStepStore::class, $guard);
    }
}
