<?php

declare(strict_types=1);

namespace App\Kafka\Saga;

use App\Enums\ReservationOutcome;
use App\Enums\SagaStep;
use App\Enums\SagaStepDecision;
use App\Enums\SagaStepStatus;
use App\Exceptions\SagaStepBusyException;
use App\Services\Stock\CommitStockService;
use App\Services\Stock\ReleaseStockService;
use App\Services\Stock\ReserveStockService;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\Saga;
use Illuminate\Support\Facades\Log;

#[Saga]
final class StockFulfillment
{
    /**
     * @param  array<string, array{status: string, locked: bool, result?: array<string, mixed>}>  $steps
     */
    private function __construct(
        #[Identifier]
        public string $sagaId,
        public array $steps = [],
    ) {}

    /**
     * @param  array<string, array{status: string, locked: bool, result?: array<string, mixed>}>  $steps
     */
    public static function restore(string $sagaId, array $steps = []): self
    {
        return new self($sagaId, $steps);
    }

    #[EventHandler]
    public static function hold(HoldStockStep $event): self
    {
        $saga = new self($event->sagaId);
        $saga->steps[$event->step->value] = [
            'status' => SagaStepStatus::Running->value,
            'locked' => true,
        ];

        return $saga;
    }

    #[EventHandler]
    public static function start(
        ApplyStockStep $event,
        ReserveStockService $reserveStock,
        CommitStockService $commitStock,
        ReleaseStockService $releaseStock,
    ): self {
        $saga = new self($event->sagaId);
        $saga->apply($event, $reserveStock, $commitStock, $releaseStock);

        return $saga;
    }

    #[EventHandler]
    public function onStep(
        ApplyStockStep $event,
        ReserveStockService $reserveStock,
        CommitStockService $commitStock,
        ReleaseStockService $releaseStock,
    ): void {
        $this->apply($event, $reserveStock, $commitStock, $releaseStock);
    }

    public function status(SagaStep|string $step): ?string
    {
        $key = $step instanceof SagaStep ? $step->value : $step;
        $status = $this->steps[$key]['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    public function isCompleted(SagaStep|string $step): bool
    {
        return in_array($this->status($step), [SagaStepStatus::Done->value, SagaStepStatus::Failed->value], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function result(SagaStep|string $step): ?array
    {
        $key = $step instanceof SagaStep ? $step->value : $step;
        $result = $this->steps[$key]['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    private function apply(
        ApplyStockStep $event,
        ReserveStockService $reserveStock,
        CommitStockService $commitStock,
        ReleaseStockService $releaseStock,
    ): void {
        $stepKey = $event->step->value;
        $decision = $this->begin($event->step);
        if ($decision === SagaStepDecision::Skip) {
            Log::info("Saga step {$stepKey} skipped", [
                'saga_id' => $this->sagaId,
                'decision' => $decision->value,
            ]);

            return;
        }

        throw_if($decision === SagaStepDecision::Busy, SagaStepBusyException::class, "Saga step {$stepKey} is busy for saga {$this->sagaId}");

        $message = new StockSagaMessage($event->orderId, $event->items, $this->sagaId);

        $outcome = match ($event->step) {
            SagaStep::Reserve => $this->reserve($message, $reserveStock),
            SagaStep::Commit => $this->commit($message, $commitStock),
            SagaStep::Release => $this->release($message, $releaseStock),
        };

        if ($outcome !== null) {
            $this->finish($event->step, $outcome['status'], $outcome['result']);

            return;
        }

        $this->unlock($event->step);
    }

    private function begin(SagaStep $step): SagaStepDecision
    {
        if ($this->isCompleted($step)) {
            return SagaStepDecision::Skip;
        }

        if (($this->steps[$step->value]['locked'] ?? false) === true) {
            return SagaStepDecision::Busy;
        }

        $this->steps[$step->value] = [
            'status' => SagaStepStatus::Running->value,
            'locked' => true,
        ];

        return SagaStepDecision::Run;
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function finish(SagaStep $step, SagaStepStatus $status, ?array $result): void
    {
        $stored = [
            'status' => $status->value,
            'locked' => false,
        ];
        if ($result !== null) {
            $stored['result'] = $result;
        }

        $this->steps[$step->value] = $stored;
    }

    private function unlock(SagaStep $step): void
    {
        if (! isset($this->steps[$step->value])) {
            return;
        }

        $this->steps[$step->value]['locked'] = false;
    }

    /**
     * @return array{status: SagaStepStatus, result: array<string, mixed>}
     */
    private function reserve(StockSagaMessage $message, ReserveStockService $reservations): array
    {
        $result = $reservations->handle($message);
        if ($result->outcome === ReservationOutcome::ReservationFailed) {
            return [
                'status' => SagaStepStatus::Failed,
                'result' => $result->toArray(),
            ];
        }

        return [
            'status' => SagaStepStatus::Done,
            'result' => $result->toArray(),
        ];
    }

    /**
     * @return array{status: SagaStepStatus, result: array<string, mixed>}|null
     */
    private function commit(StockSagaMessage $message, CommitStockService $reservations): ?array
    {
        if ($this->isCompleted(SagaStep::Release)) {
            Log::info('stock.commit skipped: stock.release already completed', [
                'order_id' => $message->orderId,
                'saga_id' => $this->sagaId,
            ]);

            return [
                'status' => SagaStepStatus::Done,
                'result' => ['order_id' => $message->orderId, 'outcome' => 'skipped_after_release'],
            ];
        }

        if (! $reservations->handle($message)) {
            return null;
        }

        return [
            'status' => SagaStepStatus::Done,
            'result' => ['order_id' => $message->orderId],
        ];
    }

    /**
     * @return array{status: SagaStepStatus, result: array<string, mixed>}|null
     */
    private function release(StockSagaMessage $message, ReleaseStockService $reservations): ?array
    {
        if ($this->isCompleted(SagaStep::Commit)) {
            Log::info('stock.release skipped: stock.commit already completed', [
                'order_id' => $message->orderId,
                'saga_id' => $this->sagaId,
            ]);

            return [
                'status' => SagaStepStatus::Done,
                'result' => ['order_id' => $message->orderId, 'outcome' => 'skipped_after_commit'],
            ];
        }

        if (! $reservations->handle($message)) {
            return null;
        }

        return [
            'status' => SagaStepStatus::Done,
            'result' => ['order_id' => $message->orderId],
        ];
    }
}
