<?php

namespace App\Kafka\Handlers;

use App\Services\SagaStepGuard;
use App\Services\StockReservationService;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class StockReleaseHandler
{
    public function __construct(
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

        if ($sagaId !== null) {
            $decision = $this->saga->tryBegin($sagaId, 'stock.release');
            if ($decision !== 'run') {
                Log::info('Saga step stock.release skipped', [
                    'saga_id' => $sagaId,
                    'decision' => $decision,
                ]);

                return;
            }
        }

        try {
            $released = $this->reservations->release($orderId, $items, $sagaId);

            if ($sagaId !== null && $released) {
                $this->saga->put(
                    $sagaId,
                    'stock.release',
                    SagaStepGuard::STATUS_DONE,
                    ['order_id' => $orderId]
                );
            }
        } catch (\Throwable $e) {
            Log::error("Ошибка при освобождении остатка для заказа #{$orderId}:", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } finally {
            if ($sagaId !== null) {
                $this->saga->release($sagaId, 'stock.release');
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
