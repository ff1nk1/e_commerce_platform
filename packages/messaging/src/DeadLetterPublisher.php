<?php

declare(strict_types=1);

namespace Shop\Messaging;

/**
 * Publishes a consumed record that cannot be processed to a dead-letter topic.
 * Implementations must only return after the broker acknowledged the write,
 * because the adapter commits the original offset on success.
 */
interface DeadLetterPublisher
{
    public function publish(ConsumedRecord $record, string $topic, string $reason): void;
}
