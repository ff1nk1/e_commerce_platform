<?php

namespace App\Services\Auth;

use App\Events\UserRegistered;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private JwtService $jwtService,
        private TokenBlacklistService $blacklist
    ) {}

    /**
     * Регистрация пользователя
     */
    public function register(array $userData): User
    {
        // Password cast on User hashes plain text — do not Hash::make here.
        $userData['role_id'] = Role::where('slug', 'customer')->valueOrFail('id');

        $user = User::create($userData);

        event(new UserRegistered($user));

        return $user;
    }

    /**
     * Проверка данных и генерация пары токенов
     */
    public function login(array $credentials): array
    {
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Неверный логин или пароль.'],
            ]);
        }

        return $this->generateTokens($user);
    }

    /**
     * Обновление токенов
     */
    public function refreshTokens(?string $oldRefreshToken): ?array
    {
        if (! $oldRefreshToken) {
            return null;
        }

        $oldHash = hash('sha256', $oldRefreshToken);
        $oldKey = "refresh_token:{$oldHash}";

        if ($this->blacklist->isRefreshTokenBlacklisted($oldHash)) {
            return null;
        }

        $userId = Redis::get($oldKey);
        if (! $userId) {
            return null;
        }

        $user = User::find($userId);
        if (! $user) {
            Redis::del($oldKey);
            $this->forgetUserRefreshHash((int) $userId, $oldHash);

            return null;
        }

        $oldTtl = Redis::ttl($oldKey);
        $this->blacklist->blacklistRefreshToken($oldHash, $oldTtl);
        Redis::del($oldKey);
        $this->forgetUserRefreshHash((int) $user->id, $oldHash);

        $tokens = $this->generateTokens($user);
        $tokens['user'] = $user;

        return $tokens;
    }

    /**
     * Инвалидация текущей пары токенов
     */
    public function logout(?string $accessToken, ?string $refreshToken): void
    {
        if ($accessToken) {
            $this->jwtService->revokeToken($accessToken);
        }

        if ($refreshToken) {
            $hash = hash('sha256', $refreshToken);
            $key = "refresh_token:{$hash}";

            $userId = Redis::get($key);
            $ttl = Redis::ttl($key);
            $this->blacklist->blacklistRefreshToken($hash, $ttl);
            Redis::del($key);

            if ($userId) {
                $this->forgetUserRefreshHash((int) $userId, $hash);
            }
        }
    }

    /**
     * Revoke access token (if provided) and every refresh token issued for the user.
     */
    public function revokeAllSessionsForUser(User $user, ?string $accessToken = null): void
    {
        if ($accessToken) {
            $this->jwtService->revokeToken($accessToken);
        }

        $indexKey = $this->userRefreshIndexKey((int) $user->id);
        $hashes = Redis::smembers($indexKey) ?: [];

        foreach ($hashes as $hash) {
            $key = "refresh_token:{$hash}";
            $ttl = Redis::ttl($key);
            $this->blacklist->blacklistRefreshToken((string) $hash, $ttl);
            Redis::del($key);
        }

        Redis::del($indexKey);
    }

    public function verifyPassword(User $user, string $password): User
    {
        if (! Hash::check($password, $user->password)) {
            throw new \Exception('Неверный пароль');
        }

        return $user;
    }

    public function archivePassword(User $user): void
    {
        $user->deleted_password = $user->password;
        $user->password = Str::random(60);
        $user->save();
    }

    public function setNewPassword(User $user, string $newPassword): void
    {
        $user->password = $newPassword;
        $user->save();
    }

    /**
     * Приватный метод для переиспользуемой логики создания токенов
     */
    private function generateTokens(User $user): array
    {
        $accessToken = $this->jwtService->createAccessToken($user);

        $refreshToken = bin2hex(random_bytes(64));
        $refreshHash = hash('sha256', $refreshToken);
        $refreshTtl = (int) config('jwt.refresh_ttl');

        Redis::setex("refresh_token:{$refreshHash}", $refreshTtl, $user->id);

        $indexKey = $this->userRefreshIndexKey((int) $user->id);
        Redis::sadd($indexKey, $refreshHash);
        Redis::expire($indexKey, $refreshTtl);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ];
    }

    private function userRefreshIndexKey(int $userId): string
    {
        return "user_refresh_tokens:{$userId}";
    }

    private function forgetUserRefreshHash(int $userId, string $hash): void
    {
        Redis::srem($this->userRefreshIndexKey($userId), $hash);
    }
}
