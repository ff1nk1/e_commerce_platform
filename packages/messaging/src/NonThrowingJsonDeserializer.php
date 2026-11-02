<?php

declare(strict_types=1);

namespace Shop\Messaging;

use JsonException;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageDeserializer;
use Junges\Kafka\Message\ConsumedMessage;

/**
 * JSON deserializer that never throws on a malformed payload. When decoding
 * fails the raw body is preserved as a string, so the inbound adapter can treat
 * it as a permanent error and route the record to the dead-letter topic instead
 * of the consumer crashing before the handler ever runs.
 */
final class NonThrowingJsonDeserializer implements MessageDeserializer
{
    public function deserialize(ConsumerMessage $message): ConsumerMessage
    {
        $raw = $message->getBody();

        if ($raw === null) {
            $body = null;
        } elseif (is_string($raw)) {
            $body = $this->decode($raw);
        } else {
            $body = $raw;
        }

        return new ConsumedMessage(
            topicName: $message->getTopicName(),
            partition: $message->getPartition(),
            headers: $message->getHeaders(),
            body: $body,
            key: $message->getKey(),
            offset: $message->getOffset(),
            timestamp: $message->getTimestamp(),
        );
    }

    private function decode(string $raw): mixed
    {
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Keep the raw string so the adapter recognises this as malformed.
            return $raw;
        }
    }
}
