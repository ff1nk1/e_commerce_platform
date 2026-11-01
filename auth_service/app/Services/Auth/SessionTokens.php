<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTO\Auth\TokenPair;
use App\Models\User;
use App\Services\Auth\Tokens\RefreshTokenStore;
use App\Support\JwtConfig;

final class SessionTokens
{
    private const REFRESH_TOKEN_BYTES = 64;

    public function __construct(
        private JwtCodec $jwt,
        private RefreshTokenStore $refreshTokens,
        private JwtConfig $config,
    ) {}

    public function issue(User $user): TokenPair
    {
        $accessToken = $this->jwt->createAccessToken($user);

        $refreshToken = bin2hex(random_bytes(self::REFRESH_TOKEN_BYTES));
        $this->refreshTokens->save(
            $this->refreshTokens->hash($refreshToken),
            (int) $user->id,
            $this->config->refreshTtl,
        );

        return new TokenPair($accessToken, $refreshToken, $user);
    }
}
