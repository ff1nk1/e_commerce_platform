<?php

declare(strict_types=1);

namespace App\Providers;

use App\Messaging\NotificationInboundEventFactory;
use Illuminate\Support\ServiceProvider;
use Shop\Messaging\InboundEventFactory;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(InboundEventFactory::class, NotificationInboundEventFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
