<?php

declare(strict_types=1);

namespace App\DTO\Payments;

final readonly class StripeCheckoutSession
{
    public function __construct(
        public ?string $id,
        public ?string $url,
        public ?string $status,
        public ?string $paymentStatus,
        public ?string $paymentIntent,
        public ?int $amountTotal,
        public ?string $currency,
        public ?string $metadataPaymentId,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public static function fromArray(array $session): self
    {
        $metadata = $session['metadata'] ?? null;

        return new self(
            id: self::stringOrNull($session['id'] ?? null),
            url: self::stringOrNull($session['url'] ?? null),
            status: self::stringOrNull($session['status'] ?? null),
            paymentStatus: self::stringOrNull($session['payment_status'] ?? null),
            paymentIntent: self::stringOrNull($session['payment_intent'] ?? null),
            amountTotal: is_numeric($session['amount_total'] ?? null) ? (int) $session['amount_total'] : null,
            currency: self::stringOrNull($session['currency'] ?? null),
            metadataPaymentId: is_array($metadata) ? self::stringOrNull($metadata['payment_id'] ?? null) : null,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
