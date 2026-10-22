<?php

namespace App\Services\Stripe;

use App\Models\Order;

final class StripeCheckoutUrls
{
    /**
     * @return array{success_url: string, cancel_url: string}
     */
    public static function forOrder(Order $order): array
    {
        $orderId = (string) $order->id;

        return [
            'success_url' => str_replace(
                '{ORDER_ID}',
                $orderId,
                (string) config('services.stripe.success_url')
            ),
            'cancel_url' => str_replace(
                '{ORDER_ID}',
                $orderId,
                (string) config('services.stripe.cancel_url')
            ),
        ];
    }
}
