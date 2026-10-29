<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Str;
use Junges\Kafka\Facades\Kafka;
use Shop\Messaging\OutboxQueue;

final class KafkaOutboxRoundTripTest extends IntegrationTestCase
{
    public function test_outbox_worker_publishes_order_created_to_kafka(): void
    {
        $this->fakeKafka();
        $this->seedSnapshot();

        $create = $this->postJson(
            '/api/orders',
            $this->orderCreatePayload(),
            ['Idempotency-Key' => 'it-kafka-'.Str::uuid()]
        );
        $create->assertCreated();
        $orderId = (int) $create->json('id');

        $this->assertSame(1, OutboxQueue::count('order.created'));
        $this->assertSame(1, OutboxQueue::flush());
        $this->assertSame(0, OutboxQueue::count());

        OutboxQueue::flush();
        Kafka::assertPublishedOn('order.events', null, function ($message) use ($orderId): bool {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'order.created'
                && ($headers['source'] ?? null) === 'order-service'
                && is_array($body)
                && (int) ($body['order_id'] ?? 0) === $orderId
                && ! empty($body['saga_id'] ?? null);
        });
    }
}
