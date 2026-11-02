<?php

declare(strict_types=1);

namespace App\DTO\Payments;

use App\Models\Order;
use App\Models\Payment;

final readonly class CheckoutSessionParams
{
    private const SESSION_TTL_MINUTES = 30;

    public function __construct(
        public CheckoutUrls $urls,
        public string $mode,
        public string $clientReferenceId,
        public int $orderId,
        public int $paymentId,
        public int $expiresAt,
        public CheckoutLineItems $lineItems,
    ) {}

    public static function forPayment(Order $order, Payment $payment, CheckoutUrls $urls, CheckoutLineItems $lineItems): self
    {
        return new self(
            urls: $urls,
            mode: 'payment',
            clientReferenceId: (string) $order->id,
            orderId: (int) $order->id,
            paymentId: (int) $payment->id,
            expiresAt: now()->addMinutes(self::SESSION_TTL_MINUTES)->getTimestamp(),
            lineItems: $lineItems,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'success_url' => $this->urls->successUrl,
            'cancel_url' => $this->urls->cancelUrl,
            'client_reference_id' => $this->clientReferenceId,
            'metadata' => [
                'order_id' => (string) $this->orderId,
                'payment_id' => (string) $this->paymentId,
            ],
            'expires_at' => $this->expiresAt,
            'line_items' => $this->lineItems->toArray(),
        ];
    }
}
