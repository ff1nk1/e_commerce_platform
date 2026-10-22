<?php

namespace App\Kafka\Handlers;

use App\Services\Saga\SagaStepRunner;
use App\Services\SagaStepGuard;
use App\Services\StockReservationService;
use App\Support\StockSagaMessage;
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

        $parsed = StockSagaMessage::tryFrom($message);
        if ($parsed === null) {
            $payload = $message->getBody();
            if (! is_array($payload)) {
                Log::warning('Получено невалидное событие order.created', ['payload' => $payload]);

                return;
            }

            $orderId = $payload['order_id'] ?? null;
            $items = $payload['items'] ?? [];
            $hasOrderId = (bool) $orderId;
            $hasItems = is_array($items) && $items !== [];
            if (! $hasOrderId || ! $hasItems) {
                Log::warning('Получено пустое или невалидное событие order.created', ['payload' => $payload]);

                return;
            }

            Log::warning('order.created missing saga_id; ignoring', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $orderId = $parsed->orderId;
        $items = $parsed->items;
        $sagaId = $parsed->sagaId;

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
}
