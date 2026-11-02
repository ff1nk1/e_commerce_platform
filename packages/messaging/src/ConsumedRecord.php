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
        public readonly ?int $partition = null,
        public readonly ?int $offset = null,
        public readonly ?int $timestamp = null,
        public readonly int $attempts = 1,
    ) {}

    public function commit(): void
    {
        ($this->commitOffset)();
    }
}
