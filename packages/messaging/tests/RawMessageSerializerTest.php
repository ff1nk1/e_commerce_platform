<?php

declare(strict_types=1);

namespace Shop\Messaging\Tests;

use Junges\Kafka\Message\Message;
use PHPUnit\Framework\TestCase;
use Shop\Messaging\RawMessageSerializer;

final class RawMessageSerializerTest extends TestCase
{
    public function test_body_is_kept_verbatim(): void
    {
        $raw = '{not valid json';
        $message = Message::create('product.events')->withBody($raw);

        $serialized = (new RawMessageSerializer)->serialize($message);

        $this->assertSame($raw, $serialized->getBody());
    }

    public function test_does_not_double_encode_json_strings(): void
    {
        $message = Message::create('product.events')->withBody(['id' => 1]);

        $serialized = (new RawMessageSerializer)->serialize($message);

        $this->assertSame(['id' => 1], $serialized->getBody());
    }
}
