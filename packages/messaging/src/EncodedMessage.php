<?php

declare(strict_types=1);

namespace Shop\Messaging;

final class EncodedMessage
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $key,
        public readonly array $headers,
        public readonly array $body,
    ) {}
}
