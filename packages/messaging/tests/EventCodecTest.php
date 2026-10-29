<?php

declare(strict_types=1);

namespace Shop\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Messaging\EventCodec;
use Shop\Messaging\OutboundMessage;

final class EventCodecTest extends TestCase
{
    public function test_encode_matches_shared_contract_fixture(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/fixtures/product-updated.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $encoded = (new EventCodec)->encode(new OutboundMessage(
            topic: $fixture['topic'],
            key: $fixture['key'],
            eventType: 'product.updated',
            payload: [
                'timestamp' => '2026-10-28T12:00:00+00:00',
                'data' => ['id' => 42, 'name' => 'Phone'],
            ],
            source: 'catalog-service',
            messageId: '11111111-1111-4111-8111-111111111111',
        ));

        $this->assertSame($fixture['headers'], $encoded->headers);
        $this->assertSame($fixture['body'], $encoded->body);
        $this->assertSame($fixture['topic'], $encoded->topic);
        $this->assertSame($fixture['key'], $encoded->key);
    }

    public function test_decode_prefers_header_then_event_type_then_event(): void
    {
        $codec = new EventCodec;

        $fromHeader = $codec->decode('product.events', ['event-type' => 'product.deleted'], [
            'event_type' => 'product.updated',
            'event' => 'product.created',
        ]);
        $this->assertSame('product.deleted', $fromHeader->eventType);

        $fromBodyType = $codec->decode('catalog.events', [], [
            'event_type' => 'stock.changed',
            'event' => 'other',
        ]);
        $this->assertSame('stock.changed', $fromBodyType->eventType);

        $fromLegacy = $codec->decode('product.events', [], [
            'event' => 'product.created',
        ]);
        $this->assertSame('product.created', $fromLegacy->eventType);
    }

    public function test_decode_reads_saga_id_from_header_or_body(): void
    {
        $codec = new EventCodec;

        $fromHeader = $codec->decode('order.events', ['saga-id' => 'saga-1'], []);
        $this->assertSame('saga-1', $fromHeader->sagaId);

        $fromBody = $codec->decode('stock.events', [], ['saga_id' => 'saga-2']);
        $this->assertSame('saga-2', $fromBody->sagaId);
    }
}
