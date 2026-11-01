<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Enums\CatalogEventType;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class RecommendationInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return match (CatalogEventType::tryFrom($envelope->eventType)) {
            CatalogEventType::ProductCreated,
            CatalogEventType::ProductUpdated,
            CatalogEventType::ProductDeleted,
            CatalogEventType::ProductPublished,
            CatalogEventType::ProductUnpublished => new ProductLifecycle($envelope),
            CatalogEventType::StockChanged => new CatalogStockChanged($envelope),
            default => null,
        };
    }
}
