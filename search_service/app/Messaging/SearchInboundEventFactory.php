<?php

declare(strict_types=1);

namespace App\Messaging;

use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class SearchInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return match ($envelope->eventType) {
            'product.created', 'product.updated', 'product.deleted', 'product.published', 'product.unpublished' => new ProductLifecycle($envelope),
            'category.created', 'category.updated', 'category.deleted' => new CategoryLifecycle($envelope),
            'stock.changed' => new CatalogStockChanged($envelope),
            default => null,
        };
    }
}
