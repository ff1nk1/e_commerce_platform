<?php

declare(strict_types=1);

namespace App\Infrastructure\Stripe;

final class StripeIdempotencyKey
{
    public static function forOrder(int|string $orderId, string $operation, string $subject): string
    {
        return 'order:'.$orderId.':'.$operation.':'.$subject;
    }
}
