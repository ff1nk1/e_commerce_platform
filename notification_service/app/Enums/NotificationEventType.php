<?php

declare(strict_types=1);

namespace App\Enums;

use App\DTO\Notifications\OrderMailData;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderPaidMail;
use App\Mail\WelcomeMail;
use Illuminate\Contracts\Mail\Mailable;

enum NotificationEventType: string
{
    case OrderPaid = 'order.paid';
    case OrderConfirmed = 'order.confirmed';
    case OrderCancelled = 'order.cancelled';
    case UserRegistered = 'user.registered';

    public function mailable(OrderMailData $data): Mailable
    {
        return match ($this) {
            self::OrderPaid => new OrderPaidMail($data),
            self::OrderConfirmed => new OrderConfirmedMail($data),
            self::OrderCancelled => new OrderCancelledMail($data),
            self::UserRegistered => new WelcomeMail($data),
        };
    }
}
