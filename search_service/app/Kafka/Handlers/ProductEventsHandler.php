<?php

namespace App\Kafka\Handlers;

use App\Kafka\Concerns\ResolvesEventType;
use App\Services\SearchIndexService;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class ProductEventsHandler
{
    use ResolvesEventType;

    public function __construct(
        private readonly SearchIndexService $index,
    ) {}

    public function __invoke(ConsumerMessage $message): void
    {
        [$eventType, $body] = $this->resolveEventTypeAndBody($message);

        if (! in_array($eventType, ['product.created', 'product.updated', 'product.deleted'], true)) {
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

        if (($data['name'] ?? null) === null || ($data['store_id'] ?? null) === null) {
            Log::warning('Kafka: product event с неполными полями', [
                'event' => $eventType,
                'product_id' => (int) $id,
            ]);

            return;
        }

        $this->index->indexProduct($data, preserveStock: $eventType === 'product.updated');

        Log::info('Kafka: product indexed in OpenSearch', [
            'event' => $eventType,
            'product_id' => (int) $id,
        ]);
    }
}
