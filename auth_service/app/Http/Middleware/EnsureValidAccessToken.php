<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\JwtService;
use App\Services\Auth\TokenCookieService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * If access_token is missing/expired but refresh_token is valid, silently refresh
 * and attach new cookies to the outgoing response.
 *
 * Also sets the api guard user from cookie/bearer (via JwtService) so auth:api works
 * without a separate cookie→Authorization middleware.
 */
class EnsureValidAccessToken
{
    public function __construct(
        private AuthService $authService,
        private JwtService $jwtService,
        private TokenCookieService $tokenCookies,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->jwtService->userFromRequest($request);

        if ($user) {
            $this->setApiUser($user);

            return $next($request);
        }

        $refreshToken = $this->refreshTokenFromRequest($request);

        if (! $refreshToken) {
            return $next($request);
        }

        $tokens = $this->authService->refreshTokens($refreshToken);
        if (! $tokens) {
            return $next($request);
        }

        $request->cookies->set('access_token', $tokens['access_token']);
        $request->cookies->set('refresh_token', $tokens['refresh_token']);
        $request->headers->set('Authorization', 'Bearer '.$tokens['access_token']);

        if ($tokens['user'] instanceof User) {
            $this->setApiUser($tokens['user']);
        }

        /** @var Response $response */
        $response = $next($request);

        return $this->tokenCookies->attach($response, $tokens['access_token'], $tokens['refresh_token']);
    }

    private function setApiUser(User $user): void
    {
        Auth::guard('api')->setUser($user);
    }

    private function refreshTokenFromRequest(Request $request): ?string
    {
        $fromCookie = $request->cookie('refresh_token')
            ?? $request->cookies->get('refresh_token');

        if (is_string($fromCookie) && $fromCookie !== '') {
            return $fromCookie;
        }

        return $this->refreshTokenFromCookieHeader($request);
    }

    private function refreshTokenFromCookieHeader(Request $request): ?string
    {
        $raw = $request->headers->get('Cookie');
        if (! is_string($raw) || ! preg_match('/(?:^|;\s*)refresh_token=([^;]+)/', $raw, $m)) {
            return null;
        }

        return urldecode($m[1]);
    }
}
