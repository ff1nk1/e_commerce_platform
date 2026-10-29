<?php

declare(strict_types=1);

namespace App\Messaging;

use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class CatalogInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return match ($envelope->eventType) {
            'order.created' => new OrderCreatedMessage($envelope),
            'stock.commit_requested' => new StockCommitRequested($envelope),
            'stock.release_requested' => new StockReleaseRequested($envelope),
            default => null,
        };
    }
}
