<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Services\CatalogOutbox;
use Shop\Messaging\Topics;

final class PublishCategoryToKafka
{
    public function __construct(
        private CatalogOutbox $outbox,
    ) {}

    public function handle(CategoryCreated|CategoryUpdated|CategoryDeleted $event): void
    {
        $category = $event->category;
        $category->loadMissing(['parent']);

        $this->outbox->publish(Topics::CATEGORY_EVENTS, $event->topic(), [
            'timestamp' => now()->toIso8601String(),
            'data' => $category->toArray(),
        ], (string) $category->id);
    }
}
