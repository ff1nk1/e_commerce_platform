<?php

declare(strict_types=1);

namespace Shop\Messaging;

final class ConsumedRecord
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public readonly string $topic,
        public readonly ?string $key,
        public readonly array $headers,
        public readonly mixed $body,
        private readonly \Closure $commitOffset,
    ) {}

    public function commit(): void
    {
        ($this->commitOffset)();
    }
}
