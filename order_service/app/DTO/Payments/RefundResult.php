<?php

declare(strict_types=1);

namespace App\DTO\Payments;

final readonly class RefundResult
{
    public function __construct(
        public string $id,
        public ?string $status,
        public ?int $amount,
        public ?string $currency,
    ) {}

    /**
     * @param  array<string, mixed>  $refund
     */
    public static function fromArray(array $refund): self
    {
        $id = $refund['id'] ?? null;
        $status = $refund['status'] ?? null;
        $amount = $refund['amount'] ?? null;
        $currency = $refund['currency'] ?? null;

        return new self(
            id: is_string($id) ? $id : '',
            status: is_string($status) ? $status : null,
            amount: is_int($amount) ? $amount : null,
            currency: is_string($currency) ? $currency : null,
        );
    }
}
