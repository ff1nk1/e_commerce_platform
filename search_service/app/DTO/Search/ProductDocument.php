<?php

declare(strict_types=1);

namespace App\DTO\Search;

/**
 * Kafka product payload or flat index payload. The original array is kept so
 * document mapping stays in the OpenSearch writer.
 */
final readonly class ProductDocument
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public int $id,
        private array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            payload: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
