<?php

declare(strict_types=1);

namespace App\DTO\Orders;

final readonly class OrderCreatedPayload
{
    /**
     * @param  list<OrderLineSnapshot>  $items
     */
    public function __construct(
        public int $orderId,
        public int $userId,
        public ?string $sagaId,
        public string $totalAmount,
        public string $createdAt,
        public array $items,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'order_id' => $this->orderId,
            'user_id' => $this->userId,
            'total_amount' => $this->totalAmount,
            'created_at' => $this->createdAt,
            'items' => array_map(
                static fn (OrderLineSnapshot $line): array => $line->toArray(),
                $this->items,
            ),
        ];

        if ($this->sagaId !== null && $this->sagaId !== '') {
            $payload['saga_id'] = $this->sagaId;
        }

        return $payload;
    }
}
