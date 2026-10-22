<?php

namespace App\Services;

use App\Models\Order;
use App\Services\Payment\ConfirmOrderFromPayment;
use App\Services\Payment\CreateCheckoutService;
use App\Services\Payment\FailPaymentAndReleaseStock;

/**
 * Facade over payment use-cases (HTTP + webhook entry points).
 */
class PaymentService
{
    public function __construct(
        private CreateCheckoutService $checkout,
        private ConfirmOrderFromPayment $confirm,
        private FailPaymentAndReleaseStock $fail,
    ) {}

    /**
     * @return array{checkout_url: string, payment_id: int}
     */
    public function createCheckout(Order $order, string $idempotencyKey): array
    {
        return $this->checkout->forOrder($order, $idempotencyKey);
    }

    public function handleWebhookEvent(array $event): void
    {
        $type = $event['type'] ?? null;
        $object = $event['data']['object'] ?? null;

        if (! is_array($object)) {
            return;
        }

        match ($type) {
            'checkout.session.completed' => $this->confirm->fromCheckoutSession($object),
            'checkout.session.expired' => $this->fail->fromCheckoutSession($object),
            default => null,
        };
    }
}
