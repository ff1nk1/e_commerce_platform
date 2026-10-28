<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\UserRegistered;
use App\Listeners\PublishUserRegisteredToKafka;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        UserRegistered::class => [
            PublishUserRegisteredToKafka::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
