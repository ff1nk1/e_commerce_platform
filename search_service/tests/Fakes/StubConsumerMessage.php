<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Junges\Kafka\Contracts\ConsumerMessage;

final class StubConsumerMessage implements ConsumerMessage
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        private string $eventType,
        private array $body,
        private string $topic,
        private array $headers,
    ) {}

    public function getTopicName(): string
    {
        return $this->topic;
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

    /**
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        return $this->body;
    }

    public function getKey(): string
    {
        $nested = $this->body['data'] ?? null;
        $data = is_array($nested) ? $nested : $this->body;
        $id = $data['id'] ?? $data['product_id'] ?? '0';

        return is_scalar($id) ? (string) $id : '0';
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function getTimestamp(): ?int
    {
        return null;
    }

    public function getMessageIdentifier(): string
    {
        return 'stub-'.$this->eventType.'-'.$this->getKey();
    }
}
