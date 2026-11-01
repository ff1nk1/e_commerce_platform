<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Auth\Tokens\RefreshTokenStore;

final class LogoutUserService
{
    public function __construct(
        private JwtCodec $jwt,
        private TokenBlacklist $blacklist,
        private RefreshTokenStore $refreshTokens,
    ) {}

    public function handle(?string $accessToken, ?string $refreshToken): void
    {
        if ($accessToken) {
            $this->jwt->revokeToken($accessToken);
        }

        if ($refreshToken) {
            $hash = $this->refreshTokens->hash($refreshToken);

            $userId = $this->refreshTokens->userId($hash);
            $this->blacklist->blacklistRefreshToken($hash, $this->refreshTokens->ttl($hash));
            $this->refreshTokens->delete($hash);

            if ($userId !== null) {
                $this->refreshTokens->forget($userId, $hash);
            }
        }
    }
}
