<?php

declare(strict_types=1);

namespace App\Enums;

enum StripeWebhookType: string
{
    case CheckoutSessionCompleted = 'checkout.session.completed';
    case CheckoutSessionExpired = 'checkout.session.expired';
}
