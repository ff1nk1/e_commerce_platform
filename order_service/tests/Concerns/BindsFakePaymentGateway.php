<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Contracts\PaymentGateway;
use App\Services\Stripe\StripeClient;
use Mockery;
use Mockery\MockInterface;

trait BindsFakePaymentGateway
{
    protected function bindPaymentGateway(MockInterface $stripe): void
    {
        $this->app->instance(StripeClient::class, $stripe);
        $this->app->instance(PaymentGateway::class, $stripe);
    }

    protected function mockPaymentGateway(): MockInterface
    {
        $stripe = Mockery::mock(StripeClient::class);
        $this->bindPaymentGateway($stripe);

        return $stripe;
    }
}
