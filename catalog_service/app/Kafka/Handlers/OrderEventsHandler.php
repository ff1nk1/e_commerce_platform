<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Enums\CatalogEventType;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class OrderEventsHandler
{
    public function __invoke(EnvelopeMessage $message): void
    {
        $headers = $message->getHeaders();
        $rawEventType = $headers['event-type'] ?? null;
        $eventType = is_string($rawEventType) ? CatalogEventType::tryFrom($rawEventType) : null;

        match ($eventType) {
            CatalogEventType::OrderCreated => resolve(OrderCreatedHandler::class)($message),
            CatalogEventType::StockReleaseRequested => resolve(StockReleaseHandler::class)($message),
            CatalogEventType::StockCommitRequested => resolve(StockCommitHandler::class)($message),
            default => Log::debug("Игнорируем неизвестное событие в order.events: {$rawEventType}"),
        };
    }
}
