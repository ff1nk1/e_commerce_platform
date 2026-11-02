<?php

declare(strict_types=1);

namespace App\DTO\Notifications;

final readonly class OrderMailData
{
    /**
     * @param  array<string, mixed>  $attributes  Original payload, including view-only keys
     */
    public function __construct(
        public string $email,
        public ?string $name,
        public int|string|null $orderId,
        private array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $email = $payload['email'] ?? '';
        $name = $payload['name'] ?? null;
        $orderId = $payload['order_id'] ?? null;

        return new self(
            email: is_scalar($email) ? (string) $email : '',
            name: is_string($name) ? $name : null,
            orderId: is_int($orderId) || is_string($orderId) ? $orderId : null,
            attributes: $payload,
        );
    }

    /**
     * Payload passed to the Blade views. Extra keys (amount, reason, items) stay intact.
     *
     * @return array<string, mixed>
     */
    public function toViewArray(): array
    {
        return $this->attributes;
    }
}
