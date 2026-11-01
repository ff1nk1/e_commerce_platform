<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTO\Auth\TokenPair;
use App\Models\User;
use App\Services\Auth\Tokens\RefreshTokenStore;

final class RefreshTokensService
{
    public function __construct(
        private TokenBlacklist $blacklist,
        private SessionTokens $sessions,
        private RefreshTokenStore $refreshTokens,
    ) {}

    public function handle(?string $oldRefreshToken): ?TokenPair
    {
        if (! $oldRefreshToken) {
            return null;
        }

        $oldHash = $this->refreshTokens->hash($oldRefreshToken);

        if ($this->blacklist->isRefreshTokenBlacklisted($oldHash)) {
            return null;
        }

        $userId = $this->refreshTokens->userId($oldHash);
        if ($userId === null) {
            return null;
        }

        $user = User::query()->whereKey($userId)->first();
        if (! $user) {
            $this->refreshTokens->delete($oldHash);
            $this->refreshTokens->forget($userId, $oldHash);

            return null;
        }

        $this->blacklist->blacklistRefreshToken($oldHash, $this->refreshTokens->ttl($oldHash));
        $this->refreshTokens->delete($oldHash);
        $this->refreshTokens->forget((int) $user->id, $oldHash);

        return $this->sessions->issue($user);
    }
}
