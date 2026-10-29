<?php

declare(strict_types=1);

namespace Shop\Messaging;

final class InboundEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $eventType,
        public readonly string $messageId,
        public readonly string $topic,
        public readonly ?string $key,
        public readonly ?string $sagaId,
        public readonly array $payload,
        public readonly array $headers,
    ) {}
}
