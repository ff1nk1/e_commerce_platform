<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\KafkaEventMovedToDlq;
use App\Listeners\PublishEventToDlq;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        KafkaEventMovedToDlq::class => [PublishEventToDlq::class],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
