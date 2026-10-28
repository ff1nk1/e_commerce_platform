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
use App\Listeners\PublishCategoryToKafka;
use App\Listeners\PublishProductToKafka;
use App\Listeners\WriteStockChangedOutbox;
use App\Listeners\WriteStockReservationFailedOutbox;
use App\Listeners\WriteStockReservedOutbox;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        ProductCreated::class => [PublishProductToKafka::class],
        ProductUpdated::class => [PublishProductToKafka::class],
        ProductDeleted::class => [PublishProductToKafka::class],
        ProductPublishStateChanged::class => [PublishProductToKafka::class],
        CategoryCreated::class => [PublishCategoryToKafka::class],
        CategoryUpdated::class => [PublishCategoryToKafka::class],
        CategoryDeleted::class => [PublishCategoryToKafka::class],
        StockChanged::class => [WriteStockChangedOutbox::class],
        StockReserved::class => [WriteStockReservedOutbox::class],
        StockReservationFailed::class => [WriteStockReservationFailedOutbox::class],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
