<?php

declare(strict_types=1);

namespace App\DTO\Orders;

final readonly class StockRequest
{
    /**
     * @param  list<StockLine>  $lines
     */
    public function __construct(
        public int $orderId,
        public ?string $sagaId,
        public array $lines,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'order_id' => $this->orderId,
            'items' => array_map(
                static fn (StockLine $line): array => $line->toArray(),
                $this->lines,
            ),
        ];

        if ($this->sagaId !== null && $this->sagaId !== '') {
            $payload['saga_id'] = $this->sagaId;
        }

        return $payload;
    }
}
