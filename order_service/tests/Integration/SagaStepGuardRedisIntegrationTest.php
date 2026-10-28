<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\SagaStepGuard;
use Illuminate\Support\Str;

final class SagaStepGuardRedisIntegrationTest extends IntegrationTestCase
{
    public function test_run_put_done_then_replay_skips(): void
    {
        $guard = resolve(SagaStepGuard::class);
        $sagaId = (string) Str::uuid();
        $step = 'stock.reserved';

        $this->assertSame('run', $guard->tryBegin($sagaId, $step));
        $guard->put($sagaId, $step, SagaStepGuard::STATUS_DONE, ['order_id' => 99]);
        $guard->release($sagaId, $step);

        $this->assertSame('skip', $guard->tryBegin($sagaId, $step));
        $this->assertSame(99, $guard->get($sagaId, $step)['result']['order_id'] ?? null);
    }

    public function test_busy_when_another_worker_holds_lock(): void
    {
        $guard = resolve(SagaStepGuard::class);
        $sagaId = (string) Str::uuid();
        $step = 'stock.reservation_failed';

        $this->assertTrue($guard->acquire($sagaId, $step));
        $this->assertSame('busy', $guard->tryBegin($sagaId, $step));
        $guard->release($sagaId, $step);
    }
}
