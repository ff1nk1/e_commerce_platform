<?php

declare(strict_types=1);

namespace Shop\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Messaging\JungesKafkaPublisher;

final class JungesKafkaPublisherTest extends TestCase
{
    public function test_producer_retries_are_ten_with_one_second_backoff(): void
    {
        $options = JungesKafkaPublisher::producerOptions();

        $this->assertSame(10, $options['retries']);
        $this->assertSame(1000, $options['retry.backoff.ms']);
        $this->assertSame(60000, $options['socket.timeout.ms']);
        $this->assertSame(60000, $options['message.timeout.ms']);
        $this->assertSame(30000, $options['request.timeout.ms']);
    }
}
