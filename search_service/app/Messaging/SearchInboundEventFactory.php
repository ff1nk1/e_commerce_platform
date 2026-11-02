<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Enums\SearchEventType;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class SearchInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return match (SearchEventType::tryFrom($envelope->eventType)) {
            SearchEventType::ProductCreated,
            SearchEventType::ProductUpdated,
            SearchEventType::ProductDeleted,
            SearchEventType::ProductPublished,
            SearchEventType::ProductUnpublished => new ProductLifecycle($envelope),
            SearchEventType::CategoryCreated,
            SearchEventType::CategoryUpdated,
            SearchEventType::CategoryDeleted => new CategoryLifecycle($envelope),
            SearchEventType::StockChanged => new CatalogStockChanged($envelope),
            default => null,
        };
    }
}
