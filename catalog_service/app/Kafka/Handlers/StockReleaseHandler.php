<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Services\Saga\SagaStepRunner;
use App\Services\SagaStepGuard;
use App\Services\StockReservationService;
use App\Support\StockSagaMessage;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class StockReleaseHandler
{
    public function __construct(
        private SagaStepRunner $sagaRunner,
        private SagaStepGuard $saga,
        private StockReservationService $reservations,
    ) {}

    public function __invoke(ConsumerMessage $message): void
    {
        $parsed = StockSagaMessage::tryFrom($message);
        if (! $parsed instanceof StockSagaMessage) {
            $payload = $message->getBody();
            if (! is_array($payload)) {
                Log::warning('Получено невалидное событие stock.release_requested', ['payload' => $payload]);

                return;
            }

            $orderId = $payload['order_id'] ?? null;
            $items = $payload['items'] ?? [];
            $hasOrderId = (bool) $orderId;
            $hasItems = is_array($items) && $items !== [];
            if (! $hasOrderId || ! $hasItems) {
                Log::warning('Получено невалидное событие stock.release_requested', ['payload' => $payload]);

                return;
            }

            Log::warning('stock.release_requested missing saga_id; ignoring', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $orderId = $parsed->orderId;
        $items = $parsed->items;
        $sagaId = $parsed->sagaId;

        $this->sagaRunner->run($sagaId, 'stock.release', function () use ($orderId, $items, $sagaId): ?array {
            if ($this->saga->isCompleted($sagaId, 'stock.commit')) {
                Log::info('stock.release skipped: stock.commit already completed', [
                    'order_id' => $orderId,
                    'saga_id' => $sagaId,
                ]);

                return [
                    'status' => SagaStepGuard::STATUS_DONE,
                    'result' => ['order_id' => $orderId, 'outcome' => 'skipped_after_commit'],
                ];
            }

            $released = $this->reservations->release($orderId, $items);

            if (! $released) {
                return null;
            }

            return [
                'status' => SagaStepGuard::STATUS_DONE,
                'result' => ['order_id' => $orderId],
            ];
        });
    }
}
