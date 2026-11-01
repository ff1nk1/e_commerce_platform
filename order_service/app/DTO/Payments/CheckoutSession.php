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
