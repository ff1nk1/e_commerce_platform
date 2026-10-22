<?php

namespace App\Kafka\Handlers;

use App\Services\Saga\SagaStepRunner;
use App\Services\SagaStepGuard;
use App\Services\StockReservationService;
use App\Support\StockSagaMessage;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class StockCommitHandler
{
    public function __construct(
        private SagaStepRunner $sagaRunner,
        private SagaStepGuard $saga,
        private StockReservationService $reservations,
    ) {}

    public function __invoke(ConsumerMessage $message): void
    {
        $parsed = StockSagaMessage::tryFrom($message);
        if ($parsed === null) {
            $payload = $message->getBody();
            if (! is_array($payload)) {
                Log::warning('Получено невалидное событие stock.commit_requested', ['payload' => $payload]);

                return;
            }

            $orderId = $payload['order_id'] ?? null;
            $items = $payload['items'] ?? [];
            if (! $orderId || ! is_array($items) || $items === []) {
                Log::warning('Получено невалидное событие stock.commit_requested', ['payload' => $payload]);

                return;
            }

            Log::warning('stock.commit_requested missing saga_id; ignoring', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $orderId = $parsed->orderId;
        $items = $parsed->items;
        $sagaId = $parsed->sagaId;

        $this->sagaRunner->run($sagaId, 'stock.commit', function () use ($orderId, $items, $sagaId) {
            if ($this->saga->isCompleted($sagaId, 'stock.release')) {
                Log::info('stock.commit skipped: stock.release already completed', [
                    'order_id' => $orderId,
                    'saga_id' => $sagaId,
                ]);

                return [
                    'status' => SagaStepGuard::STATUS_DONE,
                    'result' => ['order_id' => $orderId, 'outcome' => 'skipped_after_release'],
                ];
            }

            $committed = $this->reservations->commit($orderId, $items, $sagaId);

            if (! $committed) {
                return null;
            }

            return [
                'status' => SagaStepGuard::STATUS_DONE,
                'result' => ['order_id' => $orderId],
            ];
        });
    }
}
