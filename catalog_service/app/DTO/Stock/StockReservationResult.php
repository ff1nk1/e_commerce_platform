<?php

declare(strict_types=1);

namespace App\DTO\Stock;

use App\Enums\ReservationOutcome;

final readonly class StockReservationResult
{
    public function __construct(
        public ReservationOutcome $outcome,
        public int|string $orderId,
    ) {}

    /**
     * @param  array{outcome: string, order_id: int|string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            outcome: ReservationOutcome::from($data['outcome']),
            orderId: $data['order_id'],
        );
    }

    /**
     * @return array{order_id: int|string, outcome: string}
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'outcome' => $this->outcome->value,
        ];
    }
}
