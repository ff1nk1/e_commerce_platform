<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense in depth on the order admin API.
 *
 * The gateway already blocks non-GET requests for `analyst`, but this service
 * trusts the `X-User-Role` gateway header and rejects the same writes in case
 * the request arrives over the internal Docker network. A request without the
 * header (internal callers, tests) is left untouched.
 */
final class DenyAnalystWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->header('X-User-Role');

        abort_if(
            $role === 'analyst' && strtoupper($request->method()) !== 'GET',
            403,
            'Analyst role is read-only.'
        );

        return $next($request);
    }
}
