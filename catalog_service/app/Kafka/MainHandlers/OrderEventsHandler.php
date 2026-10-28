<?php

declare(strict_types=1);

namespace App\Kafka\MainHandlers;

use App\Kafka\Handlers\OrderCreatedHandler;
use App\Kafka\Handlers\StockCommitHandler;
use App\Kafka\Handlers\StockReleaseHandler;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

final class OrderEventsHandler
{
    public function __invoke(ConsumerMessage $message): void
    {
        $headers = $message->getHeaders();
        $eventType = $headers['event-type'] ?? null;

        match ($eventType) {
            'order.created' => resolve(OrderCreatedHandler::class)($message),
            'stock.release_requested' => resolve(StockReleaseHandler::class)($message),
            'stock.commit_requested' => resolve(StockCommitHandler::class)($message),
            default => Log::debug("Игнорируем неизвестное событие в order.events: {$eventType}"),
        };
    }
}
