<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Enums\CatalogEventType;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboundEventFactory;

final class CatalogInboundEventFactory implements InboundEventFactory
{
    public function make(InboundEnvelope $envelope): ?object
    {
        return match (CatalogEventType::tryFrom($envelope->eventType)) {
            CatalogEventType::OrderCreated => new OrderCreatedMessage($envelope),
            CatalogEventType::StockCommitRequested => new StockCommitRequested($envelope),
            CatalogEventType::StockReleaseRequested => new StockReleaseRequested($envelope),
            default => null,
        };
    }
}
