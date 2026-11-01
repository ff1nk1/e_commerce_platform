<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\DTO\Search\ProductDocument;
use App\Enums\SearchEventType;
use App\Kafka\Concerns\ResolvesEventType;
use App\Messaging\ProductLifecycle;
use App\Services\Indexing\ProductIndexer;
use Ecotone\Modelling\Attribute\EventHandler;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class ProductEventsHandler
{
    use ResolvesEventType;

    public function __construct(
        private readonly ProductIndexer $products,
    ) {}

    #[EventHandler(endpointId: 'search.product')]
    public function onInbound(ProductLifecycle $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        [$eventTypeRaw, $body] = $this->resolveEventTypeAndBody($message);
        $eventType = is_string($eventTypeRaw) ? SearchEventType::tryFrom($eventTypeRaw) : null;

        if ($eventType === null || ! $eventType->isProduct()) {
            Log::debug("Игнорируем неизвестное событие продукта: {$eventTypeRaw}");

            return;
        }

        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            Log::warning('Kafka: product event без data', [
                'event' => $eventType->value,
            ]);

            return;
        }

        $id = $data['id'] ?? null;
        if ($id === null) {
            Log::warning('Kafka: product event без id', [
                'event' => $eventType->value,
            ]);

            return;
        }

        /** @var array<string, mixed> $data */
        $document = ProductDocument::fromArray($data);

        if ($eventType === SearchEventType::ProductDeleted) {
            $this->products->delete($document->id);
            Log::info('Kafka: product removed from OpenSearch', ['product_id' => $document->id]);

            return;
        }

        $hasName = ($data['name'] ?? null) !== null;
        $hasStoreId = ($data['store_id'] ?? null) !== null;
        if (! $hasName || ! $hasStoreId) {
            Log::warning('Kafka: product event с неполными полями', [
                'event' => $eventType->value,
                'product_id' => $document->id,
            ]);

            return;
        }

        $this->products->upsert($document, preserveStock: true);

        Log::info('Kafka: product indexed in OpenSearch', [
            'event' => $eventType->value,
            'product_id' => $document->id,
        ]);
    }
}
