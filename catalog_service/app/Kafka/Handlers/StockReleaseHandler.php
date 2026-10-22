<?php

namespace App\Kafka\Handlers;

use App\Services\Saga\SagaStepRunner;
use App\Services\SagaStepGuard;
use App\Services\StockReservationService;
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
        $payload = $message->getBody();
        if (! is_array($payload)) {
            Log::warning('Получено невалидное событие stock.release_requested', ['payload' => $payload]);

            return;
        }

        $orderId = $payload['order_id'] ?? null;
        $items = $payload['items'] ?? [];
        $sagaId = $this->sagaIdFrom($payload);

        if (! $orderId || ! is_array($items) || $items === []) {
            Log::warning('Получено невалидное событие stock.release_requested', ['payload' => $payload]);

            return;
        }

        if ($sagaId === null) {
            Log::warning('stock.release_requested missing saga_id; ignoring', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $this->sagaRunner->run($sagaId, 'stock.release', function () use ($orderId, $items, $sagaId) {
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

            $released = $this->reservations->release($orderId, $items, $sagaId);

            if (! $released) {
                return null;
            }

            return [
                'status' => SagaStepGuard::STATUS_DONE,
                'result' => ['order_id' => $orderId],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sagaIdFrom(array $payload): ?string
    {
        $sagaId = $payload['saga_id'] ?? null;

        return is_string($sagaId) && $sagaId !== '' ? $sagaId : null;
    }
}
