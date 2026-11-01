<?php

declare(strict_types=1);

namespace App\Enums;

enum FulfillmentState: string
{
    case Placed = 'placed';
    case StockReserved = 'stock_reserved';
    case ReservationFailed = 'reservation_failed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case PaymentFailed = 'payment_failed';
}
