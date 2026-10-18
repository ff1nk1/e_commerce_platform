<?php

namespace App\Providers;

use App\Contracts\IdempotencyStore;
use App\Contracts\PaymentGateway;
use App\Contracts\SagaStepStore;
use App\Services\IdempotencyService;
use App\Services\SagaStepGuard;
use App\Services\Stripe\StripeClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IdempotencyStore::class, IdempotencyService::class);
        $this->app->singleton(SagaStepStore::class, SagaStepGuard::class);
        $this->app->singleton(StripeClient::class);
        $this->app->bind(PaymentGateway::class, StripeClient::class);
    }

    public function boot(): void
    {
        //
    }
}
