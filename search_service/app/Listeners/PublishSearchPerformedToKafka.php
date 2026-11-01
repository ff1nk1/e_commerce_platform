<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\SearchEventType;
use App\Events\SearchPerformed;
use Shop\Messaging\OutboundMessage;
use Shop\Messaging\OutboxPublisher;
use Shop\Messaging\Topics;

final class PublishSearchPerformedToKafka
{
    public function __construct(
        private OutboxPublisher $outbox,
    ) {}

    public function handle(SearchPerformed $event): void
    {
        $query = $event->query;
        $headers = [];
        if ($event->skipAnalytics) {
            $headers['skip-analytics'] = 'true';
        }

        $eventType = SearchEventType::SearchPerformed->value;

        $this->outbox->publish(new OutboundMessage(
            topic: Topics::SEARCH_EVENTS,
            key: hash('sha256', json_encode($query->toArray(), JSON_THROW_ON_ERROR).'|'.$event->resultsCount),
            eventType: $eventType,
            payload: [
                'timestamp' => now()->toIso8601String(),
                'event_type' => $eventType,
                'event' => $eventType,
                'skip_analytics' => $event->skipAnalytics,
                'data' => [
                    'query' => $query->q,
                    'filters' => [
                        'category_ids' => $query->categoryIds,
                        'min_price' => $query->minPrice,
                        'max_price' => $query->maxPrice,
                        'in_stock' => $query->inStock,
                        'attributes' => $query->attributes,
                    ],
                    'results_count' => $event->resultsCount,
                    'sort' => $query->sort->value,
                ],
            ],
            source: 'search-service',
            extraHeaders: $headers,
        ));
    }
}
