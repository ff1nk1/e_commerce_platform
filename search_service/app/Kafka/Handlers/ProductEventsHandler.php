<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Kafka\Concerns\ResolvesEventType;
use App\Messaging\ProductLifecycle;
use App\Services\SearchIndexService;
use Ecotone\Modelling\Attribute\EventHandler;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class ProductEventsHandler
{
    use ResolvesEventType;

    public function __construct(
        private readonly SearchIndexService $index,
    ) {}

    #[EventHandler(endpointId: 'search.product')]
    public function onInbound(ProductLifecycle $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        [$eventType, $body] = $this->resolveEventTypeAndBody($message);

        if (! in_array($eventType, ['product.created', 'product.updated', 'product.deleted', 'product.published', 'product.unpublished'], true)) {
            Log::debug("Игнорируем неизвестное событие продукта: {$eventType}");

            return;
        }

        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            Log::warning('Kafka: product event без data', [
                'event' => $eventType,
            ]);

            return;
        }

        $id = $data['id'] ?? null;
        if ($id === null) {
            Log::warning('Kafka: product event без id', [
                'event' => $eventType,
            ]);

            return;
        }

        if ($eventType === 'product.deleted') {
            $this->index->deleteProduct((int) $id);
            Log::info('Kafka: product removed from OpenSearch', ['product_id' => (int) $id]);

            return;
        }

        $hasName = ($data['name'] ?? null) !== null;
        $hasStoreId = ($data['store_id'] ?? null) !== null;
        if (! $hasName || ! $hasStoreId) {
            Log::warning('Kafka: product event с неполными полями', [
                'event' => $eventType,
                'product_id' => (int) $id,
            ]);

            return;
        }

        $this->index->indexProduct($data, preserveStock: true);

        Log::info('Kafka: product indexed in OpenSearch', [
            'event' => $eventType,
            'product_id' => (int) $id,
        ]);
    }
}
