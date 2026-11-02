<?php

declare(strict_types=1);

namespace App\Infrastructure\Stripe;

use App\DTO\Payments\CheckoutLineItems;
use App\DTO\Payments\CheckoutSessionParams;
use App\DTO\Payments\CheckoutUrls;
use App\Models\Order;
use App\Models\Payment;

final class StripeCheckoutSessionParams
{
    public static function forPayment(
        Order $order,
        Payment $payment,
        CheckoutUrls $urls,
        CheckoutLineItems $lineItems,
    ): CheckoutSessionParams {
        return CheckoutSessionParams::forPayment($order, $payment, $urls, $lineItems);
    }
}
