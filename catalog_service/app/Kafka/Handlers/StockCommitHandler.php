<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Messaging\ApplyStockStep;
use App\Messaging\StockCommitRequested;
use App\Support\StockSagaMessage;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\EventBus;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class StockCommitHandler
{
    public function __construct(
        private EventBus $events,
    ) {}

    #[EventHandler(endpointId: 'catalog.stockCommit')]
    public function onInbound(StockCommitRequested $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        $parsed = StockSagaMessage::tryFrom($message);
        if (! $parsed instanceof StockSagaMessage) {
            $payload = $message->getBody();
            $orderId = $payload['order_id'] ?? null;
            $items = $payload['items'] ?? [];
            $hasOrderId = (bool) $orderId;
            $hasItems = is_array($items) && $items !== [];
            if (! $hasOrderId || ! $hasItems) {
                Log::warning('Получено невалидное событие stock.commit_requested', ['payload' => $payload]);

                return;
            }

            Log::warning('stock.commit_requested missing saga_id; ignoring', [
                'order_id' => $orderId,
            ]);

            return;
        }

        try {
            $this->events->publish(new ApplyStockStep(
                $parsed->sagaId,
                'stock.commit',
                $parsed->orderId,
                $parsed->items,
            ));
        } catch (\Throwable $e) {
            Log::error('Критическая ошибка при обработке stock.commit_requested', [
                'order_id' => $parsed->orderId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            throw $e;
        }
    }
}
