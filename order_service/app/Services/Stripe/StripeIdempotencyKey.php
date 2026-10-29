<?php

declare(strict_types=1);

namespace App\Services\Stripe;

final class StripeIdempotencyKey
{
    public static function forOrder(int|string $orderId, string $operation, string $subject): string
    {
        return 'order:'.$orderId.':'.$operation.':'.$subject;
    }
}
