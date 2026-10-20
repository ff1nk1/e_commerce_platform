<?php

namespace Tests\Concerns;

use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Facades\Kafka;

trait UsesKafka
{
    protected function fakeKafka(): void
    {
        Kafka::fake();
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function stubConsumerMessage(
        string $eventType,
        array $body,
        string $topic = 'catalog.events',
        array $headers = [],
    ): ConsumerMessage {
        $headers = array_merge(['event-type' => $eventType], $headers);

        return new class($eventType, $body, $topic, $headers) implements ConsumerMessage
        {
            /**
             * @param  array<string, mixed>  $body
             * @param  array<string, string>  $headers
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

            public function getHeaders(): array
            {
                return $this->headers;
            }

            public function getBody(): array
            {
                return $this->body;
            }

            public function getKey(): string
            {
                $data = $this->body['data'] ?? $this->body;

                return (string) ($data['id'] ?? $data['product_id'] ?? '0');
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
        };
    }
}
