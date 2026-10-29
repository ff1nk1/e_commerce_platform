<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Junges\Kafka\Facades\Kafka;
use RuntimeException;
use Shop\Messaging\Ecotone\KafkaOutboxRelay;
use Shop\Messaging\MessagePublisher;
use Shop\Messaging\OutboundMessage;
use Shop\Messaging\OutboxPublisher;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class KafkaOutboxWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_writes_stock_changed_inside_the_transaction(): void
    {
        DB::transaction(function (): void {
            resolve(OutboxPublisher::class)->publish($this->message());
            $stored = OutboxQueue::first('stock.changed');
            $this->assertNotNull($stored);
            $this->assertSame('catalog.events', $stored->topic);
            $this->assertSame(5, $stored->payload['quantity'] ?? null);
        });

        $this->assertSame(1, OutboxQueue::count('stock.changed'));
    }

    public function test_relay_publishes_event_type_header_and_body(): void
    {
        Kafka::fake();

        resolve(KafkaOutboxRelay::class)->relay($this->message());

        Kafka::assertPublishedOn('catalog.events', null, function ($message): bool {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'stock.changed'
                && ($headers['source'] ?? null) === 'catalog-service'
                && ($body['event_type'] ?? null) === 'stock.changed'
                && ($body['event'] ?? null) === 'stock.changed'
                && ($body['product_id'] ?? null) === 1
                && ($body['quantity'] ?? null) === 5;
        });
    }

    public function test_flush_keeps_the_row_when_publish_fails(): void
    {
        resolve(OutboxPublisher::class)->publish($this->message());

        $publisher = \Mockery::mock(MessagePublisher::class);
        $publisher->shouldReceive('publish')->once()->andThrow(new RuntimeException('broker down'));
        $this->app->instance(MessagePublisher::class, $publisher);

        try {
            OutboxQueue::flush();
            $this->fail('flush should rethrow');
        } catch (RuntimeException) {
        }

        $this->assertNotNull(OutboxQueue::first('stock.changed'));
    }

    public function test_flush_returns_zero_when_the_channel_is_empty(): void
    {
        Kafka::fake();

        $this->assertSame(0, OutboxQueue::flush());
        Kafka::assertNothingPublished();
    }

    private function message(): OutboundMessage
    {
        return new OutboundMessage(
            topic: 'catalog.events',
            key: '1',
            eventType: 'stock.changed',
            payload: [
                'product_id' => 1,
                'store_id' => 2,
                'quantity' => 5,
                'event_type' => 'stock.changed',
                'event' => 'stock.changed',
            ],
            source: 'catalog-service',
        );
    }
}
