<?php

declare(strict_types=1);

namespace App\DTO\Payments;

final readonly class CheckoutLineItems
{
    /**
     * @param  list<CheckoutLineItem>  $items
     */
    public function __construct(
        public array $items,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (CheckoutLineItem $item): array => $item->toArray(),
            $this->items,
        );
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
