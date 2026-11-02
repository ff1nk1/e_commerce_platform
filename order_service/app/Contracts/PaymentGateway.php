<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Payments\CheckoutSessionParams;
use App\DTO\Payments\RefundResult;
use App\DTO\Payments\StripeCheckoutSession;
use App\DTO\Payments\WebhookEvent;

interface PaymentGateway
{
    public function createCheckoutSession(CheckoutSessionParams $params, string $idempotencyKey): StripeCheckoutSession;

    public function retrieveCheckoutSession(string $sessionId): StripeCheckoutSession;

    public function expireCheckoutSession(string $sessionId, string $idempotencyKey): StripeCheckoutSession;

    public function constructWebhookEvent(string $payload, string $signatureHeader): WebhookEvent;

    public function refundPaymentIntent(string $paymentIntentId, ?int $amountMinor, string $idempotencyKey): RefundResult;
}
