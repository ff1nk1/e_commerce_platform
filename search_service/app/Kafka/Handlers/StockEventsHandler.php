<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Kafka\Concerns\ResolvesEventType;
use App\Messaging\CatalogStockChanged;
use App\Services\SearchIndexService;
use Ecotone\Modelling\Attribute\EventHandler;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class StockEventsHandler
{
    use ResolvesEventType;

    public function __construct(
        private readonly SearchIndexService $index,
    ) {}

    #[EventHandler(endpointId: 'search.stock')]
    public function onInbound(CatalogStockChanged $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
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
