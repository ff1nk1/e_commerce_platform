<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, function (): Client {
            $hosts = config('opensearch.hosts', ['http://search_opensearch:9200']);

            return ClientBuilder::create()
                ->setHosts($hosts)
                ->build();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('search', function (Request $request) {
            $max = max(1, (int) config('opensearch.throttle_per_minute', 60));

            return Limit::perMinute($max)->by($request->ip() ?: 'unknown');
        });
    }
}
