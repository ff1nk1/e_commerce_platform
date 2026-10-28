<?php

declare(strict_types=1);

namespace App\Support;

final class SqlLike
{
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
