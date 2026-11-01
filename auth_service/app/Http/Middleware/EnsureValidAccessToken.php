<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTO\Auth\TokenPair;
use App\Models\User;
use App\Services\Auth\JwtCodec;
use App\Services\Auth\RefreshTokensService;
use App\Services\Auth\TokenCookies;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * If access_token is missing/expired but refresh_token is valid, silently refresh
 * and attach new cookies to the outgoing response.
 *
 * Also sets the api guard user from cookie/bearer (via JwtCodec) so auth:api works
 * without a separate cookie→Authorization middleware.
 */
final class EnsureValidAccessToken
{
    public function __construct(
        private RefreshTokensService $refreshTokens,
        private JwtCodec $jwt,
        private TokenCookies $tokenCookies,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->jwt->userFromRequest($request);

        if ($user instanceof User) {
            $this->setApiUser($user);

            return $next($request);
        }

        $refreshToken = $this->tokenCookies->refreshTokenFrom($request);

        if (! $refreshToken) {
            return $next($request);
        }

        $tokens = $this->refreshTokens->handle($refreshToken);
        if (! $tokens instanceof TokenPair) {
            return $next($request);
        }

        $request->cookies->set(TokenCookies::ACCESS_COOKIE, $tokens->accessToken);
        $request->cookies->set(TokenCookies::REFRESH_COOKIE, $tokens->refreshToken);
        $request->headers->set('Authorization', 'Bearer '.$tokens->accessToken);

        if ($tokens->user instanceof User) {
            $this->setApiUser($tokens->user);
        }

        /** @var Response $response */
        $response = $next($request);

        return $this->tokenCookies->attach($response, $tokens);
    }

    private function setApiUser(User $user): void
    {
        Auth::guard('api')->setUser($user);
    }
}
