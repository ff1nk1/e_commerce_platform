<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Shop\Messaging\OutboundMessage;
use Shop\Messaging\OutboxPublisher;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class OutboxWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_embeds_event_type_into_the_channel_row(): void
    {
        resolve(OutboxPublisher::class)->publish(new OutboundMessage(
            topic: 'order.events',
            key: '18',
            eventType: 'order.paid',
            payload: [
                'order_id' => 18,
                'email' => 'buyer@example.com',
                'event_type' => 'order.paid',
                'event' => 'order.paid',
            ],
            source: 'order-service',
        ));

        $stored = OutboxQueue::first('order.paid');
        $this->assertNotNull($stored);
        $this->assertSame('order.paid', $stored->event_type);
        $this->assertSame('order.events', $stored->topic);
        $this->assertSame('order.paid', $stored->payload['event_type'] ?? null);
        $this->assertSame(18, $stored->payload['order_id'] ?? null);
    }
}
