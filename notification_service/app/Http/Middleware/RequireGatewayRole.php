<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the email log API with the gateway-injected role header.
 *
 * Only `admin` and `analyst` may read the log (PII). `analyst` is read-only:
 * any non-GET request (e.g. resend) is rejected with 403.
 */
final class RequireGatewayRole
{
    /** @var list<string> */
    private const ALLOWED_ROLES = ['admin', 'analyst'];

    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->header('X-User-Role');

        abort_unless(\in_array($role, self::ALLOWED_ROLES, true), 403, 'Admin gateway headers required.');

        abort_if($role === 'analyst' && strtoupper($request->method()) !== 'GET', 403, 'Analyst role is read-only.');

        return $next($request);
    }
}
