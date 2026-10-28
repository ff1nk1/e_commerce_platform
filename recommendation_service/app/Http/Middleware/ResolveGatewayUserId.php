<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prefer gateway-injected X-User-Id over body/query user_id when present.
 */
final class ResolveGatewayUserId
{
    public function handle(Request $request, Closure $next): Response
    {
        $headerUserId = $request->header('X-User-Id');
        if (is_string($headerUserId) && trim($headerUserId) !== '') {
            $request->merge(['user_id' => trim($headerUserId)]);
        }

        return $next($request);
    }
}
