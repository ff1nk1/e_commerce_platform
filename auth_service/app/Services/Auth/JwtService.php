<?php

namespace App\Services\Auth;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class JwtService
{
    private string $secret;

    private string $algorithm;

    private int $ttl;

    public function __construct(
        private TokenBlacklistService $blacklist
    ) {
        $this->secret = (string) config('jwt.secret');
        $this->algorithm = (string) config('jwt.algorithm', 'HS256');
        $this->ttl = (int) config('jwt.ttl', 15) * 60;

        if ($this->secret === '') {
            throw new \RuntimeException('JWT_SECRET is not configured.');
        }
    }

    /**
     * Создание access JWT.
     */
    public function createAccessToken(User $user): string
    {
        $now = now();

        $payload = [
            'sub' => $user->getAuthIdentifier(),
            'iat' => $now->getTimestamp(),
            'exp' => $now->copy()->addSeconds($this->ttl)->getTimestamp(),
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode(
            $payload,
            $this->secret,
            $this->algorithm
        );
    }

    /**
     * Проверка и декодирование JWT.
     */
    public function decode(string $token): \stdClass
    {
        return JWT::decode(
            $token,
            new Key($this->secret, $this->algorithm)
        );
    }

    /**
     * Получить User из Authorization: Bearer ...
     */
    public function userFromRequest(Request $request): ?User
    {
        $token = $request->cookie('access_token') ?? $request->bearerToken();

        if (! $token) {
            return null;
        }

        try {
            $payload = $this->decode($token);

            if (! isset($payload->sub) || ! isset($payload->jti)) {
                return null;
            }

            if ($this->blacklist->isJwtBlacklisted($payload->jti)) {
                return null;
            }

            $user = User::find($payload->sub);

            return $user;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Помещает access-токен в blacklist по его jti.
     *
     * TTL blacklist-записи = оставшееся время жизни токена.
     * Как только токен сам истечёт — запись исчезнет из Redis.
     */
    public function revokeToken(string $token): void
    {
        try {
            $payload = $this->decode($token);

            if (! isset($payload->jti) || ! isset($payload->exp)) {
                return;
            }

            $remainingTtl = (int) now()->diffInSeconds(
                Carbon::createFromTimestamp((int) $payload->exp),
                false
            );

            /*
             * Токен уже истёк — блэклистить нечего,
             * он и так никого не пустит.
             */
            if ($remainingTtl <= 0) {
                return;
            }

            $this->blacklist->blacklistJwt($payload->jti, $remainingTtl);
        } catch (Throwable) {
            /*
             * Битый токен — блэклистить нечего.
             * Просто молча выходим.
             */
        }
    }
}
