<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Auth\Tokens\RefreshTokenStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ChangePasswordService
{
    public function __construct(
        private JwtCodec $jwt,
        private TokenBlacklist $blacklist,
        private RefreshTokenStore $refreshTokens,
    ) {}

    /**
     * @throws ValidationException when the current password does not match
     */
    public function handle(User $user, string $currentPassword, string $newPassword, ?string $accessToken): void
    {
        $user = DB::transaction(function () use ($user, $currentPassword, $newPassword): User {
            if (! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Неверный пароль'],
                ]);
            }

            $user->deleted_password = $user->password;
            $user->password = Str::random(60);
            $user->save();

            $user->password = $newPassword;
            $user->save();

            return $user;
        });

        $this->revokeAllSessions($user, $accessToken);
    }

    private function revokeAllSessions(User $user, ?string $accessToken): void
    {
        if ($accessToken) {
            $this->jwt->revokeToken($accessToken);
        }

        $userId = (int) $user->id;

        foreach ($this->refreshTokens->hashesFor($userId) as $hash) {
            $this->blacklist->blacklistRefreshToken($hash, $this->refreshTokens->ttl($hash));
            $this->refreshTokens->delete($hash);
        }

        $this->refreshTokens->deleteUserIndex($userId);
    }
}
