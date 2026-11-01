<?php

declare(strict_types=1);

namespace App\Enums;

enum ReservationOutcome: string
{
    case Reserved = 'reserved';
    case ReservationFailed = 'reservation_failed';
}
