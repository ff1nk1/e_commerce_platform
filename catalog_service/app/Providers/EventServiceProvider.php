<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Events\ProductCreated;
use App\Events\ProductDeleted;
use App\Events\ProductPublishStateChanged;
use App\Events\ProductUpdated;
use App\Events\StockChanged;
use App\Events\StockReservationFailed;
use App\Events\StockReserved;
use App\Listeners\PublishCatalogEventToKafka;
use App\Listeners\WriteOutboxableToOutbox;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        ProductCreated::class => [PublishCatalogEventToKafka::class],
        ProductUpdated::class => [PublishCatalogEventToKafka::class],
        ProductDeleted::class => [PublishCatalogEventToKafka::class],
        ProductPublishStateChanged::class => [PublishCatalogEventToKafka::class],
        CategoryCreated::class => [PublishCatalogEventToKafka::class],
        CategoryUpdated::class => [PublishCatalogEventToKafka::class],
        CategoryDeleted::class => [PublishCatalogEventToKafka::class],
        StockChanged::class => [WriteOutboxableToOutbox::class],
        StockReserved::class => [WriteOutboxableToOutbox::class],
        StockReservationFailed::class => [WriteOutboxableToOutbox::class],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
