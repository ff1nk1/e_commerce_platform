<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\JwtCodec;
use App\Support\JwtConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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

        $this->registerRateLimiters();
    }

    /**
     * Named throttles for the public perimeter.
     *
     * - `login`: brute-force protection keyed by Email + IP.
     * - `search`: per-IP DDoS cap for the public search proxy.
     */
    private function registerRateLimiters(): void
    {
        if (RateLimiter::limiter('login') === null) {
            RateLimiter::for('login', function (Request $request) {
                $email = Str::lower((string) $request->input('email'));

                return Limit::perMinute(5)->by($email.'|'.$request->ip());
            });
        }

        if (RateLimiter::limiter('search') === null) {
            RateLimiter::for('search', function (Request $request) {
                return Limit::perMinute(60)->by($request->ip() ?: 'unknown');
            });
        }
    }
}
