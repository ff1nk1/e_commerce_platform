<?php

declare(strict_types=1);

namespace Shop\Messaging;

/**
 * Header and body view of an inbound envelope.
 * Existing handlers call getHeaders() and getBody().
 */
final class EnvelopeMessage
{
    public function __construct(
        public readonly InboundEnvelope $envelope,
    ) {}

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->envelope->headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        return $this->envelope->payload;
    }

    public function getKey(): ?string
    {
        return $this->envelope->key;
    }
}
