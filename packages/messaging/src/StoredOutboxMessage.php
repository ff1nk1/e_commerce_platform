<?php

declare(strict_types=1);

namespace Shop\Messaging;

final class StoredOutboxMessage
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $topic,
        public readonly string $event_type,
        public readonly array $payload,
        public readonly OutboundMessage $message,
    ) {}
}
