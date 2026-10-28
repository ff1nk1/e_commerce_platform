<?php

declare(strict_types=1);

namespace App\Support;

final class RefreshTokenKeys
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function redisKey(string $hash): string
    {
        return "refresh_token:{$hash}";
    }

    public static function userIndexKey(int $userId): string
    {
        return "user_refresh_tokens:{$userId}";
    }
}
