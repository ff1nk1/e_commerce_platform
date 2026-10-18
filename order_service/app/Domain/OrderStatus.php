<?php

namespace App\Domain;

enum OrderStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
    case PaymentFailed = 'payment_failed';

    public function canCancel(): bool
    {
        return match ($this) {
            self::Pending, self::AwaitingPayment => true,
            default => false,
        };
    }

    public function canPay(): bool
    {
        return $this === self::AwaitingPayment;
    }

    public function canShip(): bool
    {
        return $this === self::Confirmed;
    }

    public function canConfirmFromPayment(): bool
    {
        return $this === self::AwaitingPayment;
    }

    public function canMarkAwaitingPayment(): bool
    {
        return $this === self::Pending;
    }

    public function canFailPayment(): bool
    {
        return $this === self::AwaitingPayment;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::AwaitingPayment, self::Cancelled],
            self::AwaitingPayment => [self::Confirmed, self::Cancelled, self::PaymentFailed],
            self::Confirmed => [self::Shipped],
            self::Shipped, self::Cancelled, self::PaymentFailed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
