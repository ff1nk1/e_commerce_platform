<?php

namespace App\Services\Stripe;

use App\Models\Order;
use App\Models\Payment;

final class StripeCheckoutSessionParams
{
    private const SESSION_TTL_SECONDS = 30 * 60;

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @return array<string, mixed>
     */
    public static function forPayment(Order $order, Payment $payment, array $lineItems): array
    {
        $urls = StripeCheckoutUrls::forOrder($order);

        return [
            'mode' => 'payment',
            'success_url' => $urls['success_url'],
            'cancel_url' => $urls['cancel_url'],
            'client_reference_id' => (string) $order->id,
            'metadata' => [
                'order_id' => (string) $order->id,
                'payment_id' => (string) $payment->id,
            ],
            'expires_at' => time() + self::SESSION_TTL_SECONDS,
            'line_items' => $lineItems,
        ];
    }
}
