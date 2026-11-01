<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTO\Auth\TokenPair;
use App\Support\JwtConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single place that knows the auth cookie names: reads tokens from requests
 * (cookie first, then Bearer) and writes/expires them on responses.
 */
final class TokenCookies
{
    public const ACCESS_COOKIE = 'access_token';

    public const REFRESH_COOKIE = 'refresh_token';

    private const COOKIE_FORGET_MINUTES = -2628000;

    public function __construct(
        private JwtConfig $config,
    ) {}

    public function accessTokenFrom(Request $request): ?string
    {
        return $this->cookieValue($request, self::ACCESS_COOKIE)
            ?? $this->nonEmpty($request->bearerToken());
    }

    public function refreshTokenFrom(Request $request): ?string
    {
        return $this->cookieValue($request, self::REFRESH_COOKIE)
            ?? $this->rawCookieHeaderValue($request, self::REFRESH_COOKIE);
    }

    /**
     * Attach access + refresh HttpOnly cookies with correct TTLs.
     */
    public function attach(Response $response, TokenPair $tokens): Response
    {
        $this->setCookie($response, self::ACCESS_COOKIE, $tokens->accessToken, $this->config->accessCookieMinutes());
        $this->setCookie($response, self::REFRESH_COOKIE, $tokens->refreshToken, $this->config->refreshCookieMinutes());

        return $response;
    }

    /**
     * Expire auth cookies on the response.
     */
    public function clear(Response $response): Response
    {
        $this->setCookie($response, self::ACCESS_COOKIE, '', self::COOKIE_FORGET_MINUTES);
        $this->setCookie($response, self::REFRESH_COOKIE, '', self::COOKIE_FORGET_MINUTES);

        return $response;
    }

    /**
     * JSON success body with token cookies attached.
     *
     * @param  array<string, mixed>  $payload
     */
    public function jsonWithTokens(TokenPair $tokens, array $payload = ['message' => 'Успешно.']): JsonResponse
    {
        $response = response()->json($payload);

        $this->attach($response, $tokens);

        return $response;
    }

    /**
     * @param  non-empty-string  $name
     */
    private function setCookie(Response $response, string $name, string $value, int $minutes): void
    {
        $response->headers->setCookie(cookie(
            $name,
            $value,
            $minutes,
            '/',
            $this->config->cookieDomain,
            $this->config->cookieSecure,
            true,
            false,
            'lax'
        ));
    }

    private function cookieValue(Request $request, string $name): ?string
    {
        return $this->nonEmpty($request->cookie($name) ?? $request->cookies->get($name));
    }

    private function rawCookieHeaderValue(Request $request, string $name): ?string
    {
        $raw = $request->headers->get('Cookie');
        $pattern = '/(?:^|;\s*)'.preg_quote($name, '/').'=([^;]+)/';

        if (! is_string($raw) || ! preg_match($pattern, $raw, $m)) {
            return null;
        }

        return $this->nonEmpty(urldecode($m[1]));
    }

    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
