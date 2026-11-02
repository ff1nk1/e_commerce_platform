<?php

declare(strict_types=1);

namespace Shop\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Messaging\KafkaDeadLetterPublisher;
use Shop\Messaging\Topics;

final class TopicsTest extends TestCase
{
    public function test_dlq_suffix_is_appended_once(): void
    {
        $this->assertSame('product.events.dlq', Topics::dlqFor('product.events'));
        $this->assertSame('product.events.dlq', Topics::dlqFor('product.events.dlq'));
        $this->assertSame('product.events.dlq', Topics::dlqFor('product.events', '.dlq'));
    }

    public function test_dlq_producer_options_wait_for_all_acks(): void
    {
        $options = KafkaDeadLetterPublisher::producerOptions();

        $this->assertSame('all', $options['acks']);
        $this->assertTrue($options['enable.idempotence']);
    }
}
