<?php

declare(strict_types=1);

namespace App\DTO\Orders;

use App\Models\Order;

final readonly class OrderView
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private array $data,
    ) {}

    public static function fromOrder(Order $order): self
    {
        $order->loadMissing(['items.productSnapshot', 'payments']);

        $data = $order->toArray();
        $data['status'] = $order->statusEnum()->value;

        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
