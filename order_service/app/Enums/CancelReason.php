<?php

declare(strict_types=1);

namespace App\Enums;

enum CancelReason: string
{
    case UserCancelled = 'user_cancelled';
    case PaymentTimeout = 'payment_timeout';
    case InsufficientStock = 'insufficient_stock';
}
