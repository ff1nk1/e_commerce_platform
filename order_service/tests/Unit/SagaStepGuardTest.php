<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SagaStepGuard;
use Illuminate\Support\Str;
use Tests\Concerns\UsesRedis;
use Tests\TestCase;

final class SagaStepGuardTest extends TestCase
{
    use UsesRedis;

    private SagaStepGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableRedis();
        $this->guard = new SagaStepGuard;
    }

    public function test_try_begin_returns_run_then_skip_after_put_done(): void
    {
        $sagaId = (string) Str::uuid();
        $step = 'stock.reserved';

        $this->assertSame('run', $this->guard->tryBegin($sagaId, $step));
        $this->guard->put($sagaId, $step, SagaStepGuard::STATUS_DONE, ['order_id' => 1]);
        $this->guard->release($sagaId, $step);

        $this->assertTrue($this->guard->isCompleted($sagaId, $step));
        $this->assertSame('skip', $this->guard->tryBegin($sagaId, $step));

        $stored = $this->guard->get($sagaId, $step);
        $this->assertSame(SagaStepGuard::STATUS_DONE, $stored['status'] ?? null);
        $this->assertSame(1, $stored['result']['order_id'] ?? null);
    }

    public function test_try_begin_returns_busy_when_lock_held(): void
    {
        $sagaId = (string) Str::uuid();
        $step = 'stock.reservation_failed';

        $this->assertTrue($this->guard->acquire($sagaId, $step));
        $this->assertSame('busy', $this->guard->tryBegin($sagaId, $step));

        $this->guard->release($sagaId, $step);
        $this->assertSame('run', $this->guard->tryBegin($sagaId, $step));
        $this->guard->release($sagaId, $step);
    }

    public function test_try_begin_double_check_skips_if_completed_while_waiting_for_lock(): void
    {
        $sagaId = (string) Str::uuid();
        $step = 'order.created';

        $this->guard->put($sagaId, $step, SagaStepGuard::STATUS_FAILED, ['reason' => 'x']);

        $this->assertSame('skip', $this->guard->tryBegin($sagaId, $step));
        $this->assertTrue($this->guard->isCompleted($sagaId, $step));
    }
}
