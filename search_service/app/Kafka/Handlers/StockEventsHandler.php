<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Enums\SearchEventType;
use App\Kafka\Concerns\ResolvesEventType;
use App\Messaging\CatalogStockChanged;
use App\Services\Indexing\ProductIndexer;
use Ecotone\Modelling\Attribute\EventHandler;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class StockEventsHandler
{
    use ResolvesEventType;

    public function __construct(
        private readonly ProductIndexer $products,
    ) {}

    #[EventHandler(endpointId: 'search.stock')]
    public function onInbound(CatalogStockChanged $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        [$eventTypeRaw, $body] = $this->resolveEventTypeAndBody($message);
        $eventType = is_string($eventTypeRaw) ? SearchEventType::tryFrom($eventTypeRaw) : null;

        if ($eventType !== SearchEventType::StockChanged) {
            Log::debug("Игнорируем событие catalog.events: {$eventTypeRaw}");

            return;
        }

        $productId = $body['product_id'] ?? null;
        $quantity = $body['quantity'] ?? null;

        if ($productId === null || $quantity === null) {
            Log::warning('Kafka: stock.changed с неполными полями', [
                'event' => $eventType->value,
            ]);

            return;
        }

        $quantityInt = (int) $quantity;
        $inStock = $quantityInt > 0;
        $this->products->updateStock((int) $productId, $quantityInt);

        Log::info('Kafka: product stock updated in OpenSearch', [
            'product_id' => (int) $productId,
            'quantity' => $quantityInt,
            'in_stock' => $inStock,
        ]);
    }
}
