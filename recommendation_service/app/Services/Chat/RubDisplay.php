<?php

namespace App\Services\Chat;

final class RubDisplay
{
    public static function format(float|string|null $price): string
    {
        if ($price === null || $price === '') {
            return 'цена не указана';
        }

        $formattedAmount = rtrim(rtrim(number_format((float) $price, 2, '.', ''), '0'), '.');

        return $formattedAmount.' ₽';
    }
}
