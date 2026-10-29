<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Messaging\FulfillmentEvents;
use App\Messaging\StockReservationFailed;
use App\Messaging\StockReserved;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Request;
use Junges\Kafka\Facades\Kafka;
use Shop\Messaging\InboundEnvelope;

trait UsesKafka
{
    protected function kafkaBrokers(): string
    {
        $fromEnv = Env::get('KAFKA_BROKERS', Request::server('KAFKA_BROKERS') ?? getenv('KAFKA_BROKERS'));
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return (string) config('kafka.brokers', 'kafka:29092');
    }

    /**
     * Fake Kafka broker for outbox publish assertions (avoids flaky consume / live workers).
     */
    protected function fakeKafka(): void
    {
        Kafka::fake();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function deliverStock(string $eventType, array $body): void
    {
        $sagaId = is_string($body['saga_id'] ?? null) ? $body['saga_id'] : '';
        $envelope = new InboundEnvelope(
            eventType: $eventType,
            messageId: ($sagaId !== '' ? $sagaId : 'test').'-'.$eventType,
            topic: 'stock.events',
            key: isset($body['order_id']) && is_scalar($body['order_id']) ? (string) $body['order_id'] : null,
            sagaId: $sagaId !== '' ? $sagaId : null,
            payload: $body,
            headers: ['event-type' => $eventType],
        );
        $event = $eventType === 'stock.reservation_failed'
            ? new StockReservationFailed($envelope)
            : new StockReserved($envelope);
        resolve(FulfillmentEvents::class)->publish($event, $sagaId);
    }
}
