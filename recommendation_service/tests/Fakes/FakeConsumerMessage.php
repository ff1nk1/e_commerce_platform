<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Junges\Kafka\Contracts\ConsumerMessage;

final class FakeConsumerMessage implements ConsumerMessage
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        private array $body,
        private array $headers = [],
        private mixed $key = null,
        private ?string $topicName = null,
    ) {}

    public function getKey(): mixed
    {
        return $this->key;
    }

    public function getTopicName(): ?string
    {
        return $this->topicName;
    }

    public function getPartition(): int
    {
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getMessageIdentifier(): string
    {
        return (string) ($this->headers['laravel-kafka::message-id'] ?? $this->key ?? uniqid('msg_', true));
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function getTimestamp(): int
    {
        return now()->getTimestamp();
    }
}
