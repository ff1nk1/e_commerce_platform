<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchStrategy: string
{
    case Numeric = 'numeric';
    case Text = 'text';
    case SizeRange = 'size_range';
}
