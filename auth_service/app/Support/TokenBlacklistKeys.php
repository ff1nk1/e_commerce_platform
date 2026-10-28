<?php

declare(strict_types=1);

namespace App\Support;

final class TokenBlacklistKeys
{
    public static function jwt(string $jti): string
    {
        return "jwt:blacklist:{$jti}";
    }

    public static function refresh(string $hash): string
    {
        return "refresh_token:blacklist:{$hash}";
    }
}
