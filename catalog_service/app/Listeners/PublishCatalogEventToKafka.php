<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Events\ProductCreated;
use App\Events\ProductDeleted;
use App\Events\ProductPublishStateChanged;
use App\Events\ProductUpdated;
use App\Kafka\CatalogOutbox;
use Shop\Messaging\Topics;

final class PublishCatalogEventToKafka
{
    public function __construct(
        private CatalogOutbox $outbox,
    ) {}

    public function handle(
        ProductCreated|ProductUpdated|ProductDeleted|ProductPublishStateChanged|CategoryCreated|CategoryUpdated|CategoryDeleted $event,
    ): void {
        if ($event instanceof CategoryCreated || $event instanceof CategoryUpdated || $event instanceof CategoryDeleted) {
            $category = $event->category;
            $category->loadMissing(['parent']);

            $this->outbox->publish(Topics::CATEGORY_EVENTS, $event->topic()->value, [
                'timestamp' => now()->toIso8601String(),
                'data' => $category->toArray(),
            ], (string) $category->id);

            return;
        }

        $product = $event->product;
        $eventType = $event instanceof ProductPublishStateChanged
            ? $event->topic
            : $event->topic();

        $product->loadMissing(['store', 'category', 'attributes']);

        $this->outbox->publish(Topics::PRODUCT_EVENTS, $eventType->value, [
            'timestamp' => now()->toIso8601String(),
            'data' => $product->toArray(),
        ], (string) $product->id);
    }
}
