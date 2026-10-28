<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OutboxEvent;
use App\Services\OutboxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OutboxWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_embeds_event_type_into_payload(): void
    {
        $event = resolve(OutboxWriter::class)->write('order.events', 'order.paid', [
            'order_id' => 18,
            'email' => 'buyer@example.com',
        ]);

        $this->assertSame('order.paid', $event->event_type);
        $this->assertSame('order.paid', $event->payload['event_type'] ?? null);
        $this->assertSame(18, $event->payload['order_id'] ?? null);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $event->id,
            'event_type' => 'order.paid',
        ]);

        $fresh = OutboxEvent::query()->findOrFail($event->id);
        $this->assertSame('order.paid', $fresh->payload['event_type'] ?? null);
    }
}
