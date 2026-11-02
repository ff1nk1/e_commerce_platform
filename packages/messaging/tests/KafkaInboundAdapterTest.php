<?php

declare(strict_types=1);

namespace Shop\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Messaging\ConsumedRecord;
use Shop\Messaging\DeadLetterPublisher;
use Shop\Messaging\EventCodec;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboxWriter;
use Shop\Messaging\KafkaConsumerLoop;
use Shop\Messaging\KafkaInboundAdapter;
use Shop\Messaging\RetryPolicy;
use Shop\Messaging\TransactionRunner;

final class KafkaInboundAdapterTest extends TestCase
{
    public function test_offset_is_committed_after_inbox_store(): void
    {
        $events = [];
        $stored = null;
        $adapter = $this->adapter(
            inbox: new class($stored) implements InboxWriter
            {
                public function __construct(private ?InboundEnvelope &$stored) {}

                public function store(InboundEnvelope $envelope): void
                {
                    $this->stored = $envelope;
                }
            },
            dlq: $this->recordingDlq($events),
        );

        $record = $this->record(commit: function () use (&$events): void {
            $events[] = 'commit';
        });

        $adapter->accept($record);

        $this->assertSame(['commit'], $events);
        $this->assertInstanceOf(InboundEnvelope::class, $stored);
        $this->assertSame('product.updated', $stored->eventType);
        $this->assertSame('m-1', $stored->messageId);
    }

    public function test_malformed_json_is_sent_to_dlq_then_committed(): void
    {
        $events = [];
        $adapter = $this->adapter(
            inbox: $this->storingInbox(),
            dlq: $this->recordingDlq($events),
        );

        $record = $this->record(body: '{not valid json', commit: function () use (&$events): void {
            $events[] = 'commit';
        });

        $adapter->accept($record);

        $this->assertSame(['dlq', 'commit'], $events);
    }

    public function test_missing_event_type_is_sent_to_dlq_then_committed(): void
    {
        $events = [];
        $adapter = $this->adapter(
            inbox: $this->storingInbox(),
            dlq: $this->recordingDlq($events),
        );

        $record = $this->record(headers: [], body: ['data' => ['id' => 1]], commit: function () use (&$events): void {
            $events[] = 'commit';
        });

        $adapter->accept($record);

        $this->assertSame(['dlq', 'commit'], $events);
    }

    public function test_transient_store_failure_is_retried_then_dlqed(): void
    {
        $events = [];
        $inbox = new class implements InboxWriter
        {
            public int $calls = 0;

            public function store(InboundEnvelope $envelope): void
            {
                $this->calls++;

                throw new \RuntimeException('inbox down');
            }
        };

        $adapter = $this->adapter(
            inbox: $inbox,
            dlq: $this->recordingDlq($events),
            policy: new RetryPolicy(maxAttempts: 3, baseDelayMs: 0, maxDelayMs: 0),
        );

        $record = $this->record(commit: function () use (&$events): void {
            $events[] = 'commit';
        });

        $adapter->accept($record);

        $this->assertSame(3, $inbox->calls);
        $this->assertSame(['dlq', 'commit'], $events);
    }

    public function test_store_recovers_before_attempt_limit_without_dlq(): void
    {
        $events = [];
        $inbox = new class implements InboxWriter
        {
            public int $calls = 0;

            public function store(InboundEnvelope $envelope): void
            {
                $this->calls++;

                throw_if($this->calls < 2, \RuntimeException::class, 'temporary');
            }
        };

        $adapter = $this->adapter(
            inbox: $inbox,
            dlq: $this->recordingDlq($events),
            policy: new RetryPolicy(maxAttempts: 3, baseDelayMs: 0, maxDelayMs: 0),
        );

        $record = $this->record(commit: function () use (&$events): void {
            $events[] = 'commit';
        });

        $adapter->accept($record);

        $this->assertSame(2, $inbox->calls);
        $this->assertSame(['commit'], $events);
    }

    public function test_persistent_store_failure_stays_uncommitted_when_dlq_is_down(): void
    {
        $committed = false;
        $inbox = new class implements InboxWriter
        {
            public function store(InboundEnvelope $envelope): void
            {
                throw new \RuntimeException('inbox down');
            }
        };

        $dlq = new class implements DeadLetterPublisher
        {
            public function publish(ConsumedRecord $record, string $topic, string $reason): void
            {
                throw new \RuntimeException('dlq down');
            }
        };

        $adapter = $this->adapter(
            inbox: $inbox,
            dlq: $dlq,
            policy: new RetryPolicy(maxAttempts: 2, baseDelayMs: 0, maxDelayMs: 0),
        );

        $record = $this->record(commit: function () use (&$committed): void {
            $committed = true;
        });

        try {
            $adapter->accept($record);
            $this->fail('dlq failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('dlq down', $e->getMessage());
        }

        $this->assertFalse($committed);
    }

    public function test_dlq_receives_preserved_identity_and_reason(): void
    {
        $captured = [];
        $dlq = new class($captured) implements DeadLetterPublisher
        {
            /** @param array<string, mixed> $captured */
            public function __construct(private array &$captured) {}

            public function publish(ConsumedRecord $record, string $topic, string $reason): void
            {
                $this->captured = ['topic' => $topic, 'reason' => $reason, 'record' => $record];
            }
        };

        $adapter = $this->adapter(inbox: $this->storingInbox(), dlq: $dlq);

        $record = $this->record(
            headers: ['event-type' => 'product.updated', 'message-id' => 'm-9'],
            body: '{broken',
            commit: static function (): void {},
            partition: 3,
            offset: 42,
        );

        $adapter->accept($record);

        $this->assertSame('product.events.dlq', $captured['topic']);
        $this->assertStringContainsString('Malformed JSON', (string) $captured['reason']);
        $this->assertSame(3, $captured['record']->partition);
        $this->assertSame(42, $captured['record']->offset);
    }

    /**
     * @param  array<int, string>  $events
     */
    private function recordingDlq(array &$events): DeadLetterPublisher
    {
        return new class($events) implements DeadLetterPublisher
        {
            /** @param array<int, string> $events */
            public function __construct(private array &$events) {}

            public function publish(ConsumedRecord $record, string $topic, string $reason): void
            {
                $this->events[] = 'dlq';
            }
        };
    }

    private function storingInbox(): InboxWriter
    {
        return new class implements InboxWriter
        {
            public function store(InboundEnvelope $envelope): void {}
        };
    }

    private function record(
        string $topic = 'product.events',
        ?string $key = '7',
        array $headers = ['event-type' => 'product.updated', 'message-id' => 'm-1'],
        mixed $body = ['name' => 'Phone'],
        ?\Closure $commit = null,
        ?int $partition = 0,
        ?int $offset = 1,
    ): ConsumedRecord {
        return new ConsumedRecord(
            topic: $topic,
            key: $key,
            headers: $headers,
            body: $body,
            commitOffset: $commit ?? static function (): void {},
            partition: $partition,
            offset: $offset,
        );
    }

    private function adapter(
        InboxWriter $inbox,
        ?DeadLetterPublisher $dlq = null,
        ?RetryPolicy $policy = null,
    ): KafkaInboundAdapter {
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
            $dlq,
            $policy ?? new RetryPolicy(maxAttempts: 1, baseDelayMs: 0, maxDelayMs: 0, dlqAttempts: 1),
        );
    }
}
