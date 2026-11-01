<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationEventType: string
{
    case OrderPaid = 'order.paid';
    case OrderConfirmed = 'order.confirmed';
    case OrderCancelled = 'order.cancelled';
    case UserRegistered = 'user.registered';
}
