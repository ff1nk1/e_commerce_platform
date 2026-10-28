<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\SearchPerformed;
use App\Listeners\PublishSearchPerformedToKafka;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        SearchPerformed::class => [
            PublishSearchPerformedToKafka::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
