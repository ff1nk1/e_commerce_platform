<?php

declare(strict_types=1);

namespace App\Support;

use Shop\Messaging\EnvelopeMessage;

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

    public static function tryFrom(EnvelopeMessage $message): ?self
    {
        $payload = $message->getBody();

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
