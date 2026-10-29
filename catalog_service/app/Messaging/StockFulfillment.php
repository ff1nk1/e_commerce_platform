<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Exceptions\SagaStepBusyException;
use App\Services\StockReservationService;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\Saga;
use Illuminate\Support\Facades\Log;

#[Saga]
final class StockFulfillment
{
    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

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
        $saga->steps[$event->step] = [
            'status' => 'running',
            'locked' => true,
        ];

        return $saga;
    }

    #[EventHandler]
    public static function start(ApplyStockStep $event, StockReservationService $reservations): self
    {
        $saga = new self($event->sagaId);
        $saga->apply($event, $reservations);

        return $saga;
    }

    #[EventHandler]
    public function onStep(ApplyStockStep $event, StockReservationService $reservations): void
    {
        $this->apply($event, $reservations);
    }

    public function status(string $step): ?string
    {
        $status = $this->steps[$step]['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    public function isCompleted(string $step): bool
    {
        return in_array($this->status($step), [self::STATUS_DONE, self::STATUS_FAILED], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function result(string $step): ?array
    {
        $result = $this->steps[$step]['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    private function apply(ApplyStockStep $event, StockReservationService $reservations): void
    {
        $decision = $this->begin($event->step);
        if ($decision === 'skip') {
            Log::info("Saga step {$event->step} skipped", [
                'saga_id' => $this->sagaId,
                'decision' => $decision,
            ]);

            return;
        }

        throw_if($decision === 'busy', SagaStepBusyException::class, "Saga step {$event->step} is busy for saga {$this->sagaId}");

        $outcome = match ($event->step) {
            'stock.reserve' => $this->reserve($event, $reservations),
            'stock.commit' => $this->commit($event, $reservations),
            'stock.release' => $this->release($event, $reservations),
            default => null,
        };

        if ($outcome !== null) {
            $this->finish($event->step, $outcome['status'], $outcome['result']);

            return;
        }

        $this->unlock($event->step);
    }

    private function begin(string $step): string
    {
        if ($this->isCompleted($step)) {
            return 'skip';
        }

        if (($this->steps[$step]['locked'] ?? false) === true) {
            return 'busy';
        }

        $this->steps[$step] = [
            'status' => 'running',
            'locked' => true,
        ];

        return 'run';
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function finish(string $step, string $status, ?array $result): void
    {
        $stored = [
            'status' => $status,
            'locked' => false,
        ];
        if ($result !== null) {
            $stored['result'] = $result;
        }

        $this->steps[$step] = $stored;
    }

    private function unlock(string $step): void
    {
        if (! isset($this->steps[$step])) {
            return;
        }

        $this->steps[$step]['locked'] = false;
    }

    /**
     * @return array{status: string, result: array<string, mixed>}
     */
    private function reserve(ApplyStockStep $event, StockReservationService $reservations): array
    {
        $result = $reservations->reserve($event->orderId, $event->items, $this->sagaId);
        if ($result['outcome'] === 'reservation_failed') {
            return [
                'status' => self::STATUS_FAILED,
                'result' => ['order_id' => $event->orderId, 'outcome' => 'reservation_failed'],
            ];
        }

        return [
            'status' => self::STATUS_DONE,
            'result' => ['order_id' => $event->orderId, 'outcome' => 'reserved'],
        ];
    }

    /**
     * @return array{status: string, result: array<string, mixed>}|null
     */
    private function commit(ApplyStockStep $event, StockReservationService $reservations): ?array
    {
        if ($this->isCompleted('stock.release')) {
            Log::info('stock.commit skipped: stock.release already completed', [
                'order_id' => $event->orderId,
                'saga_id' => $this->sagaId,
            ]);

            return [
                'status' => self::STATUS_DONE,
                'result' => ['order_id' => $event->orderId, 'outcome' => 'skipped_after_release'],
            ];
        }

        if (! $reservations->commit($event->orderId, $event->items)) {
            return null;
        }

        return [
            'status' => self::STATUS_DONE,
            'result' => ['order_id' => $event->orderId],
        ];
    }

    /**
     * @return array{status: string, result: array<string, mixed>}|null
     */
    private function release(ApplyStockStep $event, StockReservationService $reservations): ?array
    {
        if ($this->isCompleted('stock.commit')) {
            Log::info('stock.release skipped: stock.commit already completed', [
                'order_id' => $event->orderId,
                'saga_id' => $this->sagaId,
            ]);

            return [
                'status' => self::STATUS_DONE,
                'result' => ['order_id' => $event->orderId, 'outcome' => 'skipped_after_commit'],
            ];
        }

        if (! $reservations->release($event->orderId, $event->items)) {
            return null;
        }

        return [
            'status' => self::STATUS_DONE,
            'result' => ['order_id' => $event->orderId],
        ];
    }
}
