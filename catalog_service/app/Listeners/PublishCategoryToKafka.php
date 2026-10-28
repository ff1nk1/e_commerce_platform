<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Models\Category;
use App\Services\Kafka\KafkaPublisher;
use Illuminate\Support\Facades\Log;

final class PublishCategoryToKafka
{
    public function __construct(
        private KafkaPublisher $kafka,
    ) {}

    public function handle(CategoryCreated|CategoryUpdated|CategoryDeleted $event): void
    {
        $this->publish($event->topic(), $event->category);
    }

    private function publish(string $topic, Category $category): void
    {
        try {
            $category->loadMissing(['parent']);

            $this->kafka->publish(
                $topic,
                (string) $category->id,
                [
                    'event-type' => $topic,
                    'source' => 'catalog-service',
                ],
                [
                    'event' => $topic,
                    'timestamp' => now()->toIso8601String(),
                    'data' => $category->toArray(),
                ],
            );
        } catch (\Throwable $e) {
            Log::error("Kafka publish failed on {$topic}", [
                'category_id' => $category->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
