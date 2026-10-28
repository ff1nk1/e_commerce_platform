<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\KafkaOutboxWorker;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Junges\Kafka\Facades\Kafka;
use Tests\TestCase;

final class KafkaOutboxWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_batch_publishes_and_deletes_outbox_rows(): void
    {
        Kafka::fake();

        $event = OutboxEvent::query()->create([
            'topic' => 'catalog.events',
            'event_type' => 'stock.changed',
            'payload' => [
                'product_id' => 1,
                'store_id' => 2,
                'quantity' => 5,
            ],
        ]);

        $published = resolve(KafkaOutboxWorker::class)->processBatch();

        $this->assertSame(1, $published);
        $this->assertDatabaseMissing('outbox_events', ['id' => $event->id]);

        Kafka::assertPublishedOn('catalog.events', null, function ($message) use ($event): bool {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'stock.changed'
                && ($headers['source'] ?? null) === 'catalog-service'
                && ($body['product_id'] ?? null) === 1
                && ($body['store_id'] ?? null) === 2
                && ($body['quantity'] ?? null) === 5
                && (string) $message->getKey() === (string) $event->id;
        });
    }

    public function test_process_batch_keeps_row_when_kafka_fails(): void
    {
        Kafka::shouldReceive('publish')
            ->once()
            ->andThrow(new \Exception('broker down'));

        $event = OutboxEvent::query()->create([
            'topic' => 'stock.events',
            'event_type' => 'stock.reserved',
            'payload' => ['order_id' => 42, 'status' => 'success'],
        ]);

        $published = resolve(KafkaOutboxWorker::class)->processBatch();

        $this->assertSame(0, $published);
        $this->assertDatabaseHas('outbox_events', ['id' => $event->id]);
    }

    public function test_process_batch_returns_zero_when_empty(): void
    {
        Kafka::fake();

        $this->assertSame(0, resolve(KafkaOutboxWorker::class)->processBatch());
        Kafka::assertNothingPublished();
    }

    public function test_process_batch_returns_zero_when_outbox_table_missing(): void
    {
        Kafka::fake();

        Schema::dropIfExists('outbox_events');

        $this->assertSame(0, resolve(KafkaOutboxWorker::class)->processBatch());
        Kafka::assertNothingPublished();
    }
}
