<?php

namespace App\Services\Saga;

use App\Contracts\SagaStepStore;
use App\Exceptions\SagaStepBusyException;
use Illuminate\Support\Facades\Log;

class SagaStepRunner
{
    public function __construct(
        private SagaStepStore $saga,
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

        if ($decision === 'busy') {
            throw new SagaStepBusyException("Saga step {$step} is busy for saga {$sagaId}");
        }

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
