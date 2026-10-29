<?php

declare(strict_types=1);

namespace Shop\Messaging;

final class OutboundMessage
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $extraHeaders
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $key,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly string $source,
        public readonly ?string $sagaId = null,
        public readonly ?string $messageId = null,
        public readonly array $extraHeaders = [],
    ) {}
}
