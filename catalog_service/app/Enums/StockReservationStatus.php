<?php

declare(strict_types=1);

namespace App\Enums;

enum StockReservationStatus: string
{
    case Held = 'held';
    case Committed = 'committed';
    case Released = 'released';
}
