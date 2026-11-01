<?php

declare(strict_types=1);

namespace App\DTO\Payments;

final readonly class StripeCheckoutSession
{
    public function __construct(
        public mixed $id,
        public mixed $url,
        public mixed $status,
        public mixed $paymentStatus,
        public mixed $paymentIntent,
        public mixed $amountTotal,
        public mixed $currency,
        public mixed $metadataPaymentId,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     */
    public static function fromArray(array $session): self
    {
        $metadata = $session['metadata'] ?? null;

        return new self(
            id: $session['id'] ?? null,
            url: $session['url'] ?? null,
            status: $session['status'] ?? null,
            paymentStatus: $session['payment_status'] ?? null,
            paymentIntent: $session['payment_intent'] ?? null,
            amountTotal: $session['amount_total'] ?? null,
            currency: $session['currency'] ?? null,
            metadataPaymentId: is_array($metadata) ? ($metadata['payment_id'] ?? null) : null,
        );
    }
}
