<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ProductCreated;
use App\Events\ProductDeleted;
use App\Events\ProductPublishStateChanged;
use App\Events\ProductUpdated;
use App\Services\CatalogOutbox;
use Shop\Messaging\Topics;

final class PublishProductToKafka
{
    public function __construct(
        private CatalogOutbox $outbox,
    ) {}

    public function handle(ProductCreated|ProductUpdated|ProductDeleted|ProductPublishStateChanged $event): void
    {
        $product = $event->product;
        $eventType = $event instanceof ProductPublishStateChanged
            ? $event->topic
            : $event->topic();

        $product->loadMissing(['store', 'category', 'attributes']);

        $this->outbox->publish(Topics::PRODUCT_EVENTS, $eventType, [
            'timestamp' => now()->toIso8601String(),
            'data' => $product->toArray(),
        ], (string) $product->id);
    }
}
