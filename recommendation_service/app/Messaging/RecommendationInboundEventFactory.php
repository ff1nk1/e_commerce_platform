<?php

declare(strict_types=1);

namespace App\Messaging;

use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class RecommendationInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return match ($envelope->eventType) {
            'product.created', 'product.updated', 'product.deleted', 'product.published', 'product.unpublished' => new ProductLifecycle($envelope),
            'stock.changed' => new CatalogStockChanged($envelope),
            default => null,
        };
    }
}
