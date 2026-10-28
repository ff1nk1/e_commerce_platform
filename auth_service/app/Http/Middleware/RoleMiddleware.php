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

        abort_unless(\in_array($user->role?->slug, $roles, true), 403);

        return $next($request);
    }
}
