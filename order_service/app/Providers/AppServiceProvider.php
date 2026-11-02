<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\IdempotencyStore;
use App\Contracts\PaymentGateway;
use App\Infrastructure\Idempotency\CacheIdempotencyStore;
use App\Infrastructure\Stripe\StripeClient;
use App\Messaging\OrderInboundEventFactory;
use Illuminate\Support\ServiceProvider;
use Shop\Messaging\InboundEventFactory;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InboundEventFactory::class, OrderInboundEventFactory::class);
        $this->app->singleton(IdempotencyStore::class, CacheIdempotencyStore::class);
        $this->app->singleton(StripeClient::class);
        $this->app->bind(PaymentGateway::class, StripeClient::class);
    }

    public function boot(): void
    {
        //
    }
}
