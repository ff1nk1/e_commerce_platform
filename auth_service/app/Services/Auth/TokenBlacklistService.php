<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;

class TokenBlacklistService
{
    public function isJwtBlacklisted(string $jti): bool
    {
        return Cache::has("jwt:blacklist:{$jti}");
    }

    public function blacklistJwt(string $jti, int $ttl): void
    {
        $ttl = $this->normalizeTtl($ttl, (int) config('jwt.ttl', 15) * 60);
        if ($ttl <= 0) {
            return;
        }

        Cache::put("jwt:blacklist:{$jti}", '1', $ttl);
    }

    public function isRefreshTokenBlacklisted(string $hash): bool
    {
        return Cache::has("refresh_token:blacklist:{$hash}");
    }

    public function blacklistRefreshToken(string $hash, int $ttl): void
    {
        // Redis TTL -1 (no expiry) / 0 / missing → use configured refresh lifetime
        $ttl = $this->normalizeTtl($ttl, (int) config('jwt.refresh_ttl'));
        if ($ttl <= 0) {
            return;
        }

        Cache::put("refresh_token:blacklist:{$hash}", '1', $ttl);
    }

    private function normalizeTtl(int $ttl, int $fallback): int
    {
        if ($ttl > 0) {
            return $ttl;
        }

        return max(0, $fallback);
    }
}
