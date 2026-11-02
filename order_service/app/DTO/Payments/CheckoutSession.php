<?php

declare(strict_types=1);

namespace App\DTO\Payments;

final readonly class CheckoutSession
{
    public function __construct(
        public string $checkout_url,
        public int $payment_id,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $url = $payload['checkout_url'] ?? '';
        $paymentId = $payload['payment_id'] ?? 0;

        return new self(
            checkout_url: is_scalar($url) ? (string) $url : '',
            payment_id: is_scalar($paymentId) ? (int) $paymentId : 0,
        );
    }

    /**
     * @return array{checkout_url: string, payment_id: int}
     */
    public function toArray(): array
    {
        return [
            'checkout_url' => $this->checkout_url,
            'payment_id' => $this->payment_id,
        ];
    }
}
