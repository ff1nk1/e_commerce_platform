<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\SearchIndexService;
use Junges\Kafka\Facades\Kafka;
use Shop\Messaging\OutboxQueue;

final class SearchPerformedPublishIntegrationTest extends IntegrationTestCase
{
    public function test_http_search_publishes_search_performed_to_kafka(): void
    {
        resolve(SearchIndexService::class)->indexProduct([
            'id' => 9100,
            'name' => 'Publish Event Laptop',
            'price' => 999.0,
            'store_id' => 1,
            'store_name' => 'Main',
            'category_id' => 2,
            'category_name' => 'Computers',
            'quantity' => 1,
            'in_stock' => true,
            'attributes' => [],
        ], preserveStock: false);

        $response = $this->getJson('/api/search?q=Publish+Event+Laptop&category_id=2&sort=relevance');

        $response->assertOk()
            ->assertJsonPath('products.total', 1)
            ->assertJsonPath('products.data.0.id', 9100);

        OutboxQueue::flush();
        Kafka::assertPublishedOn('search.events', null, function ($message): bool {
            $body = $message->getBody();
            $headers = $message->getHeaders();

            return ($headers['event-type'] ?? null) === 'search.performed'
                && ($headers['source'] ?? null) === 'search-service'
                && ! isset($headers['skip-analytics'])
                && ($body['skip_analytics'] ?? null) === false
                && ($body['event'] ?? null) === 'search.performed'
                && ($body['event_type'] ?? null) === 'search.performed'
                && ($body['data']['query'] ?? null) === 'Publish Event Laptop'
                && ($body['data']['results_count'] ?? null) === 1
                && ($body['data']['sort'] ?? null) === 'relevance'
                && ($body['data']['filters']['category_ids'] ?? null) === [2];
        });
    }

    public function test_http_search_with_skip_analytics_header_still_publishes_flagged_event(): void
    {
        resolve(SearchIndexService::class)->indexProduct([
            'id' => 9102,
            'name' => 'Skip Analytics Phone',
            'price' => 10.0,
            'store_id' => 1,
            'quantity' => 1,
            'in_stock' => true,
        ], preserveStock: false);

        $this->withHeaders(['X-Skip-Analytics' => '1'])
            ->getJson('/api/search?q=Skip+Analytics+Phone')
            ->assertOk();

        OutboxQueue::flush();
        Kafka::assertPublishedOn('search.events', null, function ($message): bool {
            $body = $message->getBody();
            $headers = $message->getHeaders();

            return ($headers['event-type'] ?? null) === 'search.performed'
                && ($headers['skip-analytics'] ?? null) === 'true'
                && ($body['skip_analytics'] ?? null) === true
                && ($body['data']['query'] ?? null) === 'Skip Analytics Phone';
        });
    }

    public function test_autocomplete_does_not_publish_search_performed(): void
    {
        resolve(SearchIndexService::class)->indexProduct([
            'id' => 9101,
            'name' => 'Autocomplete Only',
            'price' => 10.0,
            'store_id' => 1,
            'quantity' => 1,
            'in_stock' => true,
        ], preserveStock: false);

        $this->getJson('/api/search/autocomplete?q=Autocom')->assertOk();

        Kafka::assertNothingPublished();
    }
}
