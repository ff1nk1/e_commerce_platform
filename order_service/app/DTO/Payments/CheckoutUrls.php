<?php

declare(strict_types=1);

namespace App\DTO\Payments;

final readonly class CheckoutUrls
{
    public function __construct(
        public string $successUrl,
        public string $cancelUrl,
    ) {}
}
