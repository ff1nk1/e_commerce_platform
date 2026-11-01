<?php

declare(strict_types=1);

namespace App\Enums;

enum SagaStep: string
{
    case Reserve = 'stock.reserve';
    case Commit = 'stock.commit';
    case Release = 'stock.release';
}
