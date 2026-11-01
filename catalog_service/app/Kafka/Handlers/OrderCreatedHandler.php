<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Enums\CatalogEventType;
use App\Enums\SagaStep;
use App\Kafka\Saga\ApplyStockStep;
use App\Kafka\Saga\StockSagaMessage;
use App\Messaging\OrderCreatedMessage;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\EventBus;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class OrderCreatedHandler
{
    public function __construct(
        private EventBus $events,
    ) {}

    #[EventHandler(endpointId: 'catalog.orderCreated')]
    public function onInbound(OrderCreatedMessage $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        $headers = $message->getHeaders();
        $eventType = $headers['event-type'] ?? null;

        if (! is_string($eventType) || CatalogEventType::tryFrom($eventType) !== CatalogEventType::OrderCreated) {
            return;
        }

        Log::info('Получено сообщение order.created:', ['body' => $message->getBody()]);

        $parsed = StockSagaMessage::tryFrom($message);
        if (! $parsed instanceof StockSagaMessage) {
            $payload = $message->getBody();
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

        try {
            $this->events->publish(new ApplyStockStep(
                $parsed->sagaId,
                SagaStep::Reserve,
                $parsed->orderId,
                $parsed->items,
            ));
        } catch (\Throwable $e) {
            Log::error('Фатальная ошибка внутри консьюмера order.created:', [
                'order_id' => $parsed->orderId,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            throw $e;
        }
    }
}
