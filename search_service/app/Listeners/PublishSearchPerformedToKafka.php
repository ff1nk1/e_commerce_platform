<?php

declare(strict_types=1);

namespace App\Listeners;

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
        $params = $event->params;
        $headers = [];
        if ($event->skipAnalytics) {
            $headers['skip-analytics'] = 'true';
        }

        $this->outbox->publish(new OutboundMessage(
            topic: Topics::SEARCH_EVENTS,
            key: hash('sha256', json_encode($params, JSON_THROW_ON_ERROR).'|'.$event->resultsCount),
            eventType: 'search.performed',
            payload: [
                'timestamp' => now()->toIso8601String(),
                'event_type' => 'search.performed',
                'event' => 'search.performed',
                'skip_analytics' => $event->skipAnalytics,
                'data' => [
                    'query' => $params['q'],
                    'filters' => [
                        'category_ids' => $params['category_ids'],
                        'min_price' => $params['min_price'],
                        'max_price' => $params['max_price'],
                        'in_stock' => $params['in_stock'],
                        'attributes' => $params['attributes'],
                    ],
                    'results_count' => $event->resultsCount,
                    'sort' => $params['sort'],
                ],
            ],
            source: 'search-service',
            extraHeaders: $headers,
        ));
    }
}
