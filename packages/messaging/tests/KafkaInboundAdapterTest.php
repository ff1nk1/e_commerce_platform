<?php

declare(strict_types=1);

namespace Shop\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Messaging\ConsumedRecord;
use Shop\Messaging\EventCodec;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboxWriter;
use Shop\Messaging\KafkaConsumerLoop;
use Shop\Messaging\KafkaInboundAdapter;
use Shop\Messaging\TransactionRunner;

final class KafkaInboundAdapterTest extends TestCase
{
    public function test_offset_is_not_committed_when_inbox_store_fails(): void
    {
        $committed = false;
        $adapter = $this->adapter(new class implements InboxWriter
        {
            public function store(InboundEnvelope $envelope): void
            {
                throw new \RuntimeException('inbox down');
            }
        });

        $record = new ConsumedRecord(
            topic: 'product.events',
            key: '1',
            headers: ['event-type' => 'product.created'],
            body: ['data' => ['id' => 1]],
            commitOffset: static function () use (&$committed): void {
                $committed = true;
            },
        );

        try {
            $adapter->accept($record);
            $this->fail('store failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('inbox down', $e->getMessage());
        }

        $this->assertFalse($committed);
    }

    public function test_offset_is_committed_after_inbox_store(): void
    {
        $committed = false;
        $stored = null;
        $adapter = $this->adapter(new class($stored) implements InboxWriter
        {
            public function __construct(private ?InboundEnvelope &$stored) {}

            public function store(InboundEnvelope $envelope): void
            {
                $this->stored = $envelope;
            }
        });

        $record = new ConsumedRecord(
            topic: 'product.events',
            key: '7',
            headers: ['event-type' => 'product.updated', 'message-id' => 'm-1'],
            body: ['name' => 'Phone'],
            commitOffset: static function () use (&$committed): void {
                $committed = true;
            },
        );

        $adapter->accept($record);

        $this->assertTrue($committed);
        $this->assertInstanceOf(InboundEnvelope::class, $stored);
        $this->assertSame('product.updated', $stored->eventType);
        $this->assertSame('m-1', $stored->messageId);
    }

    private function adapter(InboxWriter $inbox): KafkaInboundAdapter
    {
        return new KafkaInboundAdapter(
            new EventCodec,
            $inbox,
            new class implements KafkaConsumerLoop
            {
                public function consume(array $topics, string $groupId, string $offsetReset, callable $handler): void {}
            },
            new class implements TransactionRunner
            {
                public function run(callable $callback): void
                {
                    $callback();
                }
            },
        );
    }
}
