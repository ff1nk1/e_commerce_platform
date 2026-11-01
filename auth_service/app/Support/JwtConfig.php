<?php

declare(strict_types=1);

namespace App\Support;

final readonly class JwtConfig
{
    public function __construct(
        public string $secret,
        public string $algorithm,
        public int $ttlMinutes,
        public int $refreshTtl,
        public bool $cookieSecure,
        public ?string $cookieDomain,
    ) {}

    public static function fromConfig(): self
    {
        $cookieDomain = config('jwt.cookie_domain');

        return new self(
            secret: (string) config('jwt.secret'),
            algorithm: (string) (config('jwt.algorithm') ?: 'HS256'),
            ttlMinutes: (int) config('jwt.ttl', 15),
            refreshTtl: (int) config('jwt.refresh_ttl'),
            cookieSecure: (bool) config('jwt.cookie_secure'),
            cookieDomain: is_string($cookieDomain) && $cookieDomain !== '' ? $cookieDomain : null,
        );
    }

    public function ttlSeconds(): int
    {
        return $this->ttlMinutes * 60;
    }

    public function accessCookieMinutes(): int
    {
        return max(1, $this->ttlMinutes);
    }

    public function refreshCookieMinutes(): int
    {
        return max(1, (int) ceil($this->refreshTtl / 60));
    }
}
