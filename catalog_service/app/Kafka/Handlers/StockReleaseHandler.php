<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Messaging\ApplyStockStep;
use App\Messaging\StockReleaseRequested;
use App\Support\StockSagaMessage;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\EventBus;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class StockReleaseHandler
{
    public function __construct(
        private EventBus $events,
    ) {}

    #[EventHandler(endpointId: 'catalog.stockRelease')]
    public function onInbound(StockReleaseRequested $event): void
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
                Log::warning('Получено невалидное событие stock.release_requested', ['payload' => $payload]);

                return;
            }

            Log::warning('stock.release_requested missing saga_id; ignoring', [
                'order_id' => $orderId,
            ]);

            return;
        }

        try {
            $this->events->publish(new ApplyStockStep(
                $parsed->sagaId,
                'stock.release',
                $parsed->orderId,
                $parsed->items,
            ));
        } catch (\Throwable $e) {
            Log::error('Критическая ошибка при обработке stock.release_requested', [
                'order_id' => $parsed->orderId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            throw $e;
        }
    }
}
