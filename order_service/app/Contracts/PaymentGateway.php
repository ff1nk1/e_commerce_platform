<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Payments\StripeCheckoutSession;

interface PaymentGateway
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): StripeCheckoutSession;

    public function retrieveCheckoutSession(string $sessionId): StripeCheckoutSession;

    public function expireCheckoutSession(string $sessionId, string $idempotencyKey): StripeCheckoutSession;

    /**
     * @return array{id: string, type: string, data: array{object: array<string, mixed>}}
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): array;

    /**
     * @return array<string, mixed>
     */
    public function refundPaymentIntent(string $paymentIntentId, ?int $amountMinor, string $idempotencyKey): array;
}
