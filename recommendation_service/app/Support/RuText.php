<?php

declare(strict_types=1);

namespace App\Support;

final class RuText
{
    public static function fold(string $value): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim($value)));
    }
}
