<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\JwtCodec;
use App\Support\JwtConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JwtConfig::class, fn (): JwtConfig => JwtConfig::fromConfig());
    }

    public function boot(): void
    {
        Auth::viaRequest('jwt-cookie', function (Request $request) {
            return resolve(JwtCodec::class)
                ->userFromRequest($request);
        });
    }
}
