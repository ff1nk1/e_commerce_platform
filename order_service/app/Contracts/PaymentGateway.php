<?php

declare(strict_types=1);

namespace App\Contracts;

interface PaymentGateway
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $params): array;

    /**
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(string $sessionId): array;

    /**
     * @return array<string, mixed>
     */
    public function expireCheckoutSession(string $sessionId): array;

    /**
     * @return array{id: string, type: string, data: array{object: array<string, mixed>}}
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): array;

    /**
     * @return array<string, mixed>
     */
    public function refundPaymentIntent(string $paymentIntentId, ?int $amountMinor = null): array;
}
