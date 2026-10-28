<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional defense-in-depth when RECOMMENDATION_INTERNAL_TOKEN is set.
 * Gateway (or other trusted callers) must send X-Internal-Token.
 * When the config token is empty, this middleware is a no-op.
 */
final class EnsureInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('recommendations.internal_token', '');
        if ($expected === '') {
            return $next($request);
        }

        $provided = (string) $request->header('X-Internal-Token', '');
        abort_if($provided === '' || ! hash_equals($expected, $provided), 401, 'Invalid internal token');

        return $next($request);
    }
}
