<?php

declare(strict_types=1);

namespace App\DTO\Orders;

use App\Http\Resources\OrderResource;
use App\Models\Order;

/**
 * Serializes an order for HTTP responses through the whitelisting
 * {@see OrderResource} (no `saga_id`, no Stripe identifiers).
 */
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

        /** @var array<string, mixed> $data */
        $data = OrderResource::make($order)->resolve();

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
