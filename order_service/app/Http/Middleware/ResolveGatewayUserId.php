<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the acting user from the trusted gateway header.
 *
 * Identity arrives only as `X-User-Id` (the gateway no longer mirrors it into
 * the body/query). A request without the header (internal callers, tests) is
 * left untouched so the scoped FormRequests can still validate an explicit id.
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
