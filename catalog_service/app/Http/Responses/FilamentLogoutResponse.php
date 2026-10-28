<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Http\RedirectResponse;

final class FilamentLogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $domain = config('services.jwt.cookie_domain');

        // Panel has no local login — send users to the storefront login and drop JWT cookies
        // so the auth gateway stops re-attaching identity headers on /admin-catalog.
        return redirect('/login')
            ->withoutCookie('access_token', '/', $domain)
            ->withoutCookie('refresh_token', '/', $domain);
    }
}
