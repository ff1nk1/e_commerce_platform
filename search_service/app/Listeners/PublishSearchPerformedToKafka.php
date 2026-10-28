<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SearchPerformed;
use App\Services\Kafka\KafkaPublisher;
use Illuminate\Support\Facades\Log;

final class PublishSearchPerformedToKafka
{
    public function __construct(
        private KafkaPublisher $kafka,
    ) {}

    public function handle(SearchPerformed $event): void
    {
        $params = $event->params;
        $skipAnalytics = $event->skipAnalytics;

        try {
            $headers = [
                'event-type' => 'search.performed',
                'source' => 'search-service',
            ];
            if ($skipAnalytics) {
                $headers['skip-analytics'] = 'true';
            }

            $body = [
                'event' => 'search.performed',
                'event_type' => 'search.performed',
                'timestamp' => now()->toIso8601String(),
                'skip_analytics' => $skipAnalytics,
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
            ];

            $this->kafka->publish('search.events', $headers, $body);
        } catch (\Throwable $e) {
            Log::error('Kafka publish failed on search.performed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
