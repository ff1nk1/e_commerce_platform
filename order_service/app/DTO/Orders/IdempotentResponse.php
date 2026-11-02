<?php

declare(strict_types=1);

namespace App\DTO\Orders;

final readonly class IdempotentResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {}

    /**
     * @param  array<mixed, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $status = $payload['status'] ?? 0;
        $body = $payload['body'] ?? [];

        return new self(
            status: is_scalar($status) ? (int) $status : 0,
            body: is_array($body) ? $body : [],
        );
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'body' => $this->body,
        ];
    }
}
