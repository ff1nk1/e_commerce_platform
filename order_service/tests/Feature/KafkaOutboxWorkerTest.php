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

    public function test_publish_writes_channel_row_inside_the_transaction(): void
    {
        $message = $this->message('order.created');

        DB::transaction(function () use ($message): void {
            resolve(OutboxPublisher::class)->publish($message);
            $stored = OutboxQueue::first('order.created');
            $this->assertNotNull($stored);
            $this->assertSame('order.events', $stored->topic);
            $this->assertSame('order.created', $stored->payload['event_type'] ?? null);
        });

        $this->assertNotNull(OutboxQueue::first('order.created'));
    }

    public function test_failed_transaction_rolls_back_the_channel_row(): void
    {
        try {
            DB::transaction(function (): never {
                resolve(OutboxPublisher::class)->publish($this->message('order.paid'));
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, OutboxQueue::count('order.paid'));
    }

    public function test_relay_publishes_event_type_header_and_body(): void
    {
        Kafka::fake();

        resolve(KafkaOutboxRelay::class)->relay($this->message('order.paid'));

        Kafka::assertPublishedOn('order.events', null, function ($message): bool {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'order.paid'
                && ($headers['source'] ?? null) === 'order-service'
                && ($body['event_type'] ?? null) === 'order.paid'
                && ($body['event'] ?? null) === 'order.paid'
                && ($body['order_id'] ?? null) === 99;
        });
    }

    public function test_flush_keeps_the_row_when_publish_fails(): void
    {
        resolve(OutboxPublisher::class)->publish($this->message('order.created'));

        $publisher = \Mockery::mock(MessagePublisher::class);
        $publisher->shouldReceive('publish')->once()->andThrow(new RuntimeException('broker down'));
        $this->app->instance(MessagePublisher::class, $publisher);

        try {
            OutboxQueue::flush();
            $this->fail('flush should rethrow');
        } catch (RuntimeException) {
        }

        $this->assertNotNull(OutboxQueue::first('order.created'));
    }

    private function message(string $eventType): OutboundMessage
    {
        return new OutboundMessage(
            topic: 'order.events',
            key: '99',
            eventType: $eventType,
            payload: [
                'order_id' => 99,
                'event_type' => $eventType,
                'event' => $eventType,
            ],
            source: 'order-service',
        );
    }
}
