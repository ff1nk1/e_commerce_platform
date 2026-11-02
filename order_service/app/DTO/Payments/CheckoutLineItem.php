<?php

declare(strict_types=1);

namespace App\DTO\Payments;

final readonly class CheckoutLineItem
{
    public function __construct(
        public string $name,
        public int $unitAmountMinor,
        public int $quantity,
        public string $currency,
    ) {}

    /**
     * @return array{
     *     quantity: int,
     *     price_data: array{
     *         currency: string,
     *         unit_amount: int,
     *         product_data: array{name: string}
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'quantity' => $this->quantity,
            'price_data' => [
                'currency' => $this->currency,
                'unit_amount' => $this->unitAmountMinor,
                'product_data' => [
                    'name' => $this->name,
                ],
            ],
        ];
    }
}
