<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ProductCreated;
use App\Events\ProductDeleted;
use App\Events\ProductPublishStateChanged;
use App\Events\ProductUpdated;
use App\Models\Product;
use App\Services\Kafka\KafkaPublisher;
use Illuminate\Support\Facades\Log;

final class PublishProductToKafka
{
    public function __construct(
        private KafkaPublisher $kafka,
    ) {}

    public function handle(ProductCreated|ProductUpdated|ProductDeleted|ProductPublishStateChanged $event): void
    {
        $product = $event->product;
        $topic = $event instanceof ProductPublishStateChanged
            ? $event->topic
            : $event->topic();

        $this->publish($topic, $product);
    }

    private function publish(string $topic, Product $product): void
    {
        try {
            $product->loadMissing(['store', 'category', 'attributes']);

            $this->kafka->publish(
                $topic,
                (string) $product->id,
                [
                    'event-type' => $topic,
                    'source' => 'catalog-service',
                ],
                [
                    'event' => $topic,
                    'timestamp' => now()->toIso8601String(),
                    'data' => $product->toArray(),
                ],
            );
        } catch (\Throwable $e) {
            Log::error("Kafka publish failed on {$topic}", [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
