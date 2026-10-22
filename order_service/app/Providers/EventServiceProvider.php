<?php

namespace App\Providers;

use App\Events\OrderCancelled;
use App\Events\OrderConfirmed;
use App\Events\OrderCreated;
use App\Events\OrderPaid;
use App\Events\OrderShipped;
use App\Events\StockCommitRequested;
use App\Events\StockReleaseRequested;
use App\Listeners\WriteOutboxableToOutbox;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        OrderCreated::class => [WriteOutboxableToOutbox::class],
        OrderCancelled::class => [WriteOutboxableToOutbox::class],
        OrderShipped::class => [WriteOutboxableToOutbox::class],
        OrderPaid::class => [WriteOutboxableToOutbox::class],
        OrderConfirmed::class => [WriteOutboxableToOutbox::class],
        StockReleaseRequested::class => [WriteOutboxableToOutbox::class],
        StockCommitRequested::class => [WriteOutboxableToOutbox::class],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
