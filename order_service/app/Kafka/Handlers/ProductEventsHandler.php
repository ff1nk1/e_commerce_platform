<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Enums\OrderEventType;
use App\Messaging\ProductSnapshotDeleted;
use App\Messaging\ProductSnapshotReceived;
use App\Models\ProductSnapshot;
use Ecotone\Modelling\Attribute\EventHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Shop\Messaging\InboundEnvelope;

final class ProductEventsHandler
{
    #[EventHandler(endpointId: 'order.productSnapshot')]
    public function onInbound(ProductSnapshotReceived $event): void
    {
        $this($event->envelope);
    }

    #[EventHandler(endpointId: 'order.productSnapshotDeleted')]
    public function onDeleted(ProductSnapshotDeleted $event): void
    {
        $this($event->envelope);
    }

    public function __invoke(InboundEnvelope $envelope): void
    {
        if (! Schema::hasTable('product_snapshots')) {
            Log::warning('Kafka: product_snapshots table missing; skipping until migrations run');

            return;
        }

        $body = $envelope->payload;
        $eventTypeFromHeader = $envelope->headers['event-type'] ?? null;
        $eventTypeFromBody = $body['event_type'] ?? $body['event'] ?? null;
        $eventType = $eventTypeFromHeader ?? $eventTypeFromBody;

        if ($eventType === OrderEventType::ProductDeleted->value) {
            $this->markDeleted($body, $eventType);

            return;
        }

        if (! in_array($eventType, [OrderEventType::ProductCreated->value, OrderEventType::ProductUpdated->value], true)) {
            Log::debug("Игнорируем неизвестное событие продукта: {$eventType}");

            return;
        }

        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            Log::warning('Kafka: product event без data', ['body' => $body]);

            return;
        }

        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $price = $data['price'] ?? null;
        $storeId = $data['store_id'] ?? null;
        $categoryId = $data['category_id'] ?? null;
        if ($categoryId === null && isset($data['category']) && is_array($data['category'])) {
            $categoryId = $data['category']['id'] ?? null;
        }

        $storeName = null;
        if (isset($data['store']) && is_array($data['store'])) {
            $storeName = $data['store']['name'] ?? null;
        }

        $categoryName = null;
        $categorySlug = null;
        if (isset($data['category']) && is_array($data['category'])) {
            $categoryName = $data['category']['name'] ?? null;
            $categorySlug = $data['category']['slug'] ?? null;
        }

        if ($id === null || $name === null || $price === null || $storeId === null) {
            Log::warning('Kafka: product event с неполными полями снапшота', ['data' => $data]);

            return;
        }

        $attributes = [
            'name' => (string) $name,
            'price' => $price,
            'store_id' => (int) $storeId,
            'store_name' => $storeName !== null ? (string) $storeName : null,
            'category_id' => $categoryId !== null ? (int) $categoryId : null,
            'category_name' => $categoryName !== null ? (string) $categoryName : null,
            'category_slug' => $categorySlug !== null ? (string) $categorySlug : null,
            'deleted_at' => null,
        ];

        $snapshot = ProductSnapshot::query()->updateOrCreate(
            ['id' => (int) $id],
            $attributes
        );

        Log::info('Kafka: product snapshot upserted', [
            'event' => $eventType,
            'product_id' => $snapshot->id,
            'name' => $snapshot->name,
            'price' => (string) $snapshot->price,
            'category_id' => $snapshot->category_id,
            'store_name' => $snapshot->store_name,
            'category_name' => $snapshot->category_name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function markDeleted(array $body, string $eventType): void
    {
        $data = $body['data'] ?? null;
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if ($id === null) {
            Log::warning('Kafka: product.deleted без id', ['body' => $body]);

            return;
        }

        $affected = ProductSnapshot::query()
            ->whereKey((int) $id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        Log::info('Kafka: product snapshot soft-deleted', [
            'event' => $eventType,
            'product_id' => (int) $id,
            'affected' => $affected,
        ]);
    }
}
