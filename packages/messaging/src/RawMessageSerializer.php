<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Junges\Kafka\Contracts\MessageSerializer;
use Junges\Kafka\Contracts\ProducerMessage;

/**
 * Leaves the message body untouched. Dead letters carry the original payload
 * verbatim, so it must not be re-encoded (a JSON string would be double-encoded).
 */
final class RawMessageSerializer implements MessageSerializer
{
    public function serialize(ProducerMessage $message): ProducerMessage
    {
        return $message;
    }
}
