<?php

namespace App\Kafka\Handlers;

use App\Services\Saga\SagaStepRunner;
use App\Services\SagaStepGuard;
use App\Services\StockReservationService;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class OrderCreatedHandler
{
    public function __construct(
        private SagaStepRunner $sagaRunner,
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

        if ($sagaId === null) {
            Log::warning('order.created missing saga_id; ignoring', [
                'order_id' => $orderId,
            ]);

            return;
        }

        try {
            $this->sagaRunner->run($sagaId, 'stock.reserve', function () use ($orderId, $items, $sagaId) {
                $result = $this->reservations->reserve($orderId, $items, $sagaId);

                if ($result['outcome'] === 'reservation_failed') {
                    return [
                        'status' => SagaStepGuard::STATUS_FAILED,
                        'result' => ['order_id' => $orderId, 'outcome' => 'reservation_failed'],
                    ];
                }

                return [
                    'status' => SagaStepGuard::STATUS_DONE,
                    'result' => ['order_id' => $orderId, 'outcome' => 'reserved'],
                ];
            });
        } catch (\Throwable $e) {
            Log::error('Фатальная ошибка внутри консьюмера order.created:', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            // Rethrow so the consumer does not auto-commit a failed/busy message.
            throw $e;
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
