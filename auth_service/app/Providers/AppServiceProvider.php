<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Auth::viaRequest('jwt-cookie', function (Request $request) {
            return resolve(JwtService::class)
                ->userFromRequest($request);
        });
    }
}
