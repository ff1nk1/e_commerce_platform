<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Messaging\FulfillmentEvents;
use App\Messaging\StockReservationFailed;
use App\Messaging\StockReserved;
use App\Support\KafkaPayload;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;
use Shop\Messaging\InboundEnvelope;

final class StockEventsHandler
{
    public function __construct(
        private FulfillmentEvents $fulfillment,
    ) {}

    public function __invoke(EnvelopeMessage $message): void
    {
        $headers = $message->getHeaders();
        $eventType = $headers['event-type'] ?? '';
        $payload = $message->getBody();

        if ($eventType === '') {
            $eventType = $payload['event_type'] ?? $payload['event'] ?? null;
        }

        if (! is_string($eventType) || ! in_array($eventType, ['stock.reserved', 'stock.reservation_failed'], true)) {
            Log::debug('Игнорируем неизвестное событие stock.events: '.(is_string($eventType) ? $eventType : ''));

            return;
        }

        $sagaId = KafkaPayload::sagaId($payload);
        if ($sagaId === null) {
            Log::warning('Kafka '.$eventType.' missing saga_id; ignoring', [
                'order_id' => $payload['order_id'] ?? null,
            ]);

            return;
        }

        $orderKey = $payload['order_id'] ?? null;
        $envelope = new InboundEnvelope(
            eventType: $eventType,
            messageId: $sagaId.'-'.$eventType,
            topic: 'stock.events',
            key: is_scalar($orderKey) ? (string) $orderKey : null,
            sagaId: $sagaId,
            payload: $payload,
            headers: ['event-type' => $eventType],
        );

        $event = $eventType === 'stock.reserved'
            ? new StockReserved($envelope)
            : new StockReservationFailed($envelope);

        $this->fulfillment->publish($event, $sagaId);
    }
}
