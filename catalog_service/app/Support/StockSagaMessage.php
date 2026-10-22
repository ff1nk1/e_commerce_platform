<?php

namespace App\Support;

use Junges\Kafka\Contracts\ConsumerMessage;

final class StockSagaMessage
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public readonly int|string $orderId,
        public readonly array $items,
        public readonly string $sagaId,
    ) {}

    public static function tryFrom(ConsumerMessage $message): ?self
    {
        $payload = $message->getBody();
        if (! is_array($payload)) {
            return null;
        }

        $orderId = $payload['order_id'] ?? null;
        $items = $payload['items'] ?? [];
        $sagaId = KafkaPayload::sagaId($payload);

        $hasOrderId = (bool) $orderId;
        $hasItems = is_array($items) && $items !== [];
        $hasSagaId = $sagaId !== null;
        if (! $hasOrderId || ! $hasItems || ! $hasSagaId) {
            return null;
        }

        return new self($orderId, $items, $sagaId);
    }
}
