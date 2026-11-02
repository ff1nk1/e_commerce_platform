<?php

declare(strict_types=1);

namespace App\Infrastructure\Stripe;

use App\DTO\Payments\CheckoutUrls;
use App\Models\Order;

final class StripeCheckoutUrls
{
    public static function forOrder(Order $order): CheckoutUrls
    {
        $orderId = (string) $order->id;

        return new CheckoutUrls(
            successUrl: str_replace(
                '{ORDER_ID}',
                $orderId,
                (string) config('services.stripe.success_url')
            ),
            cancelUrl: str_replace(
                '{ORDER_ID}',
                $orderId,
                (string) config('services.stripe.cancel_url')
            ),
        );
    }
}
