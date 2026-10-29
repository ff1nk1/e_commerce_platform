<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Messaging\CatalogStockChanged;
use App\Services\Catalog\ProductIndexer;
use Ecotone\Modelling\Attribute\EventHandler;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class StockEventsHandler
{
    public function __construct(
        private readonly ProductIndexer $indexer,
    ) {}

    #[EventHandler(endpointId: 'recommendation.stock')]
    public function onInbound(CatalogStockChanged $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        $headers = $message->getHeaders();
        $body = $message->getBody();

        $headerEventType = $headers['event-type'] ?? null;
        $bodyEventType = $body['event'] ?? null;
        $eventType = $headerEventType ?? $bodyEventType;
        $isStockChangedEvent = $eventType === 'stock.changed';
        if (! $isStockChangedEvent) {
            Log::debug('Recommendation: ignore catalog.events', [
                'event' => is_string($eventType) ? $eventType : get_debug_type($eventType),
            ]);

            return;
        }

        $productId = $body['product_id'] ?? null;
        $quantity = $body['quantity'] ?? null;
        $hasValidProductId = $productId !== null && is_numeric($productId) && (int) $productId > 0;
        $hasValidQuantity = $quantity !== null && is_numeric($quantity);
        if (! $hasValidProductId || ! $hasValidQuantity) {
            Log::warning('Recommendation: stock.changed incomplete', [
                'body_keys' => array_keys($body),
            ]);

            return;
        }

        $inStock = ((int) $quantity) > 0;
        $this->indexer->setInStock((int) $productId, $inStock);

        Log::info('Recommendation: stock updated', [
            'product_id' => (int) $productId,
            'quantity' => (int) $quantity,
            'in_stock' => $inStock,
        ]);
    }
}
