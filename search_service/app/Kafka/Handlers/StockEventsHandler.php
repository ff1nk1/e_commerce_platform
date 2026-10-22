<?php

namespace App\Kafka\Handlers;

use App\Kafka\Concerns\ResolvesEventType;
use App\Services\SearchIndexService;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class StockEventsHandler
{
    use ResolvesEventType;

    public function __construct(
        private readonly SearchIndexService $index,
    ) {}

    public function __invoke(ConsumerMessage $message): void
    {
        [$eventType, $body] = $this->resolveEventTypeAndBody($message);

        if ($eventType !== 'stock.changed') {
            Log::debug("Игнорируем событие catalog.events: {$eventType}");

            return;
        }

        $productId = $body['product_id'] ?? null;
        $quantity = $body['quantity'] ?? null;

        if ($productId === null || $quantity === null) {
            Log::warning('Kafka: stock.changed с неполными полями', [
                'event' => $eventType,
            ]);

            return;
        }

        $quantityInt = (int) $quantity;
        $inStock = $quantityInt > 0;
        $this->index->updateProductStock((int) $productId, $quantityInt);

        Log::info('Kafka: product stock updated in OpenSearch', [
            'product_id' => (int) $productId,
            'quantity' => $quantityInt,
            'in_stock' => $inStock,
        ]);
    }
}
