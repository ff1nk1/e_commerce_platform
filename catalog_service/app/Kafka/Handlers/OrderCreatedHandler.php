<?php

namespace App\Kafka\Handlers;

use App\Services\SagaStepGuard;
use App\Services\StockReservationService;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class OrderCreatedHandler
{
    public function __construct(
        private SagaStepGuard $saga,
        private StockReservationService $reservations,
    ) {}

    public function __invoke(ConsumerMessage $message): void
    {
        $headers = $message->getHeaders();
        $eventType = $headers['event-type'] ?? null;

        if ($eventType !== 'order.created') {
            return;
        }

        Log::info('Получено сообщение order.created:', ['body' => $message->getBody()]);
        $payload = $message->getBody();

        if (! is_array($payload)) {
            Log::warning('Получено невалидное событие order.created', ['payload' => $payload]);

            return;
        }

        $orderId = $payload['order_id'] ?? null;
        $items = $payload['items'] ?? [];
        $sagaId = $this->sagaIdFrom($payload);

        if (! $orderId || ! is_array($items) || $items === []) {
            Log::warning('Получено пустое или невалидное событие order.created', ['payload' => $payload]);

            return;
        }

        if ($sagaId !== null) {
            $decision = $this->saga->tryBegin($sagaId, 'stock.reserve');
            if ($decision !== 'run') {
                Log::info('Saga step stock.reserve skipped', [
                    'saga_id' => $sagaId,
                    'decision' => $decision,
                ]);

                return;
            }
        }

        $stepStatus = SagaStepGuard::STATUS_DONE;
        $stepResult = ['order_id' => $orderId, 'outcome' => 'reserved'];

        try {
            $result = $this->reservations->reserve($orderId, $items, $sagaId);

            if ($result['outcome'] === 'reservation_failed') {
                $stepStatus = SagaStepGuard::STATUS_FAILED;
                $stepResult = ['order_id' => $orderId, 'outcome' => 'reservation_failed'];
            }

            if ($sagaId !== null) {
                $this->saga->put($sagaId, 'stock.reserve', $stepStatus, $stepResult);
            }
        } catch (\Throwable $e) {
            Log::error('Фатальная ошибка внутри консьюмера order.created:', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            // Rethrow so the consumer does not auto-commit a failed message
            // (otherwise stock.* is never written and the order stays pending).
            throw $e;
        } finally {
            if ($sagaId !== null) {
                $this->saga->release($sagaId, 'stock.reserve');
            }
        }
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
