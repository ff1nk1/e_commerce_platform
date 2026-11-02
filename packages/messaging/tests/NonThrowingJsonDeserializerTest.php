<?php

declare(strict_types=1);

namespace Shop\Messaging\Tests;

use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Message\ConsumedMessage;
use PHPUnit\Framework\TestCase;
use Shop\Messaging\NonThrowingJsonDeserializer;

final class NonThrowingJsonDeserializerTest extends TestCase
{
    public function test_valid_json_is_decoded_to_array(): void
    {
        $message = $this->message('{"name":"Phone"}');

        $decoded = (new NonThrowingJsonDeserializer)->deserialize($message);

        $this->assertSame(['name' => 'Phone'], $decoded->getBody());
    }

    public function test_malformed_json_keeps_raw_string_instead_of_throwing(): void
    {
        $message = $this->message('{not valid json');

        $decoded = (new NonThrowingJsonDeserializer)->deserialize($message);

        $this->assertSame('{not valid json', $decoded->getBody());
    }

    public function test_null_body_stays_null(): void
    {
        $decoded = (new NonThrowingJsonDeserializer)->deserialize($this->message(null));

        $this->assertNull($decoded->getBody());
    }

    private function message(mixed $body): ConsumerMessage
    {
        return new ConsumedMessage(
            topicName: 'product.events',
            partition: 0,
            headers: ['event-type' => 'product.updated'],
            body: $body,
            key: '1',
            offset: 5,
            timestamp: 1700000000,
        );
    }
}
