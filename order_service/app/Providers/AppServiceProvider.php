<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\IdempotencyStore;
use App\Contracts\PaymentGateway;
use App\Messaging\OrderInboundEventFactory;
use App\Services\IdempotencyService;
use App\Services\Stripe\StripeClient;
use Illuminate\Support\ServiceProvider;
use Shop\Messaging\InboundEventFactory;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InboundEventFactory::class, OrderInboundEventFactory::class);
        $this->app->singleton(IdempotencyStore::class, IdempotencyService::class);
        $this->app->singleton(StripeClient::class);
        $this->app->bind(PaymentGateway::class, StripeClient::class);
    }

    public function boot(): void
    {
        //
    }
}
