<?php

declare(strict_types=1);

namespace App\Services\Saga;

use App\Exceptions\SagaStepBusyException;
use App\Services\SagaStepGuard;
use Illuminate\Support\Facades\Log;

final class SagaStepRunner
{
    public function __construct(
        private SagaStepGuard $saga,
    ) {}

    /**
     * Claim a saga step, run work, persist outcome. On busy throws so the message is retried.
     *
     * @param  callable(): array{status?: string, result?: array<string, mixed>}  $work
     */
    public function run(?string $sagaId, string $step, callable $work): void
    {
        if ($sagaId === null) {
            $work();

            return;
        }

        $decision = $this->saga->tryBegin($sagaId, $step);
        if ($decision === 'skip') {
            Log::info("Saga step {$step} skipped", [
                'saga_id' => $sagaId,
                'decision' => $decision,
            ]);

            return;
        }

        throw_if($decision === 'busy', SagaStepBusyException::class, "Saga step {$step} is busy for saga {$sagaId}");

        try {
            $outcome = $work();
            if (isset($outcome['status'])) {
                $this->saga->put(
                    $sagaId,
                    $step,
                    $outcome['status'],
                    $outcome['result'] ?? null
                );
            }
        } finally {
            $this->saga->release($sagaId, $step);
        }
    }
}
