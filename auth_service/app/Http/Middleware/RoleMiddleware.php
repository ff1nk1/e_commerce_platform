<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RoleMiddleware
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$roles
    ): Response {
        $user = $request->user();

        abort_unless($user !== null, 401);

        $role = $user->role?->slug;

        abort_unless(\in_array($role, $roles, true), 403);

        // Analysts are strictly read-only: any non-GET (e.g. shipping an order)
        // is rejected at the perimeter, regardless of the matched route verb.
        abort_if($role === 'analyst' && strtoupper($request->method()) !== 'GET', 403);

        return $next($request);
    }
}
