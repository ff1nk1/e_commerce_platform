<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth role guard for the recommendation API.
 *
 * The gateway already role-gates these routes; this checks the trusted
 * `X-User-Role` header in case a request arrives directly over the internal
 * Docker network. A missing header (health checks, tests) is left untouched.
 */
final class EnsureGatewayRole
{
    /** @var list<string> */
    public const ALLOWED = ['admin', 'analyst', 'user'];

    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->header('X-User-Role');
        if (is_string($role) && trim($role) !== '') {
            abort_unless(in_array(trim($role), self::ALLOWED, true), 403, 'Role is not allowed.');
        }

        return $next($request);
    }
}
