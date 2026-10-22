<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFromGatewayHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->header('X-User-Id');
        $role = $request->header('X-User-Role');
        $hasValidUserId = is_numeric($userId) && (int) $userId >= 1;
        $isAdminRole = $role === 'admin';

        if (! $hasValidUserId || ! $isAdminRole) {
            abort(403, 'Admin gateway headers required.');
        }

        $user = User::fromGatewayHeaders(
            (int) $userId,
            (string) $role,
            $request->header('X-User-Name'),
            $request->header('X-User-Email'),
        );
        Auth::guard('gateway')->setUser($user);

        return $next($request);
    }
}
