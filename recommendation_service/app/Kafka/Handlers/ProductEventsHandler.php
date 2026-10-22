<?php

namespace App\Kafka\Handlers;

use App\Models\ProductCatalog;
use App\Services\Catalog\ProductIndexer;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class ProductEventsHandler
{
    public const ALLOWED_TYPES = [
        'product.created',
        'product.updated',
        'product.deleted',
        'product.published',
        'product.unpublished',
    ];

    public function __construct(
        private readonly ProductIndexer $indexer,
    ) {}

    public function __invoke(ConsumerMessage $message): void
    {
        $headers = $message->getHeaders();
        $body = $message->getBody();
        if (! is_array($body)) {
            $body = [];
        }

        $headerEventType = $headers['event-type'] ?? null;
        $bodyEventType = $body['event'] ?? null;
        $eventType = $headerEventType ?? $bodyEventType;
        $isAllowedEventType = is_string($eventType) && in_array($eventType, self::ALLOWED_TYPES, true);
        if (! $isAllowedEventType) {
            Log::debug('Recommendation: ignore product event', [
                'event' => is_string($eventType) ? $eventType : get_debug_type($eventType),
            ]);

            return;
        }

        $data = $body['data'] ?? null;
        $hasDataPayload = is_array($data);
        if (! $hasDataPayload) {
            Log::warning('Recommendation: product event without data', [
                'event' => $eventType,
                'body_keys' => array_keys($body),
            ]);

            return;
        }

        $id = $data['id'] ?? $data['product_id'] ?? null;
        $hasValidProductId = $id !== null && is_numeric($id) && (int) $id > 0;
        if (! $hasValidProductId) {
            Log::warning('Recommendation: product event without valid id', [
                'event' => $eventType,
                'data_keys' => array_keys($data),
            ]);

            return;
        }

        $productId = (int) $id;
        $isUpsertEvent = in_array($eventType, ['product.created', 'product.updated'], true);

        if ($isUpsertEvent && ! $this->hasUpsertPayload($data)) {
            Log::warning('Recommendation: product upsert missing required fields', [
                'event' => $eventType,
                'product_id' => $productId,
                'data_keys' => array_keys($data),
            ]);

            return;
        }

        match ($eventType) {
            'product.deleted' => $this->indexer->remove($productId),
            'product.published' => $this->handlePublished($data, $productId),
            'product.unpublished' => $this->indexer->setPublished($productId, false),
            default => $this->indexer->upsertFromEvent($data, reembed: true),
        };

        Log::info('Recommendation: product event handled', [
            'event' => $eventType,
            'product_id' => $productId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasUpsertPayload(array $data): bool
    {
        $name = $data['name'] ?? null;

        return is_string($name) && trim($name) !== '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handlePublished(array $data, int $productId): void
    {
        $data['is_published'] = true;
        $existing = ProductCatalog::query()->where('product_id', $productId)->exists();
        if ($existing && ($data['name'] ?? null) === null) {
            $this->indexer->setPublished($productId, true);

            return;
        }

        if (! $this->hasUpsertPayload($data) && ! $existing) {
            Log::warning('Recommendation: product.published for unknown product without name', [
                'product_id' => $productId,
            ]);

            return;
        }

        $this->indexer->upsertFromEvent($data, reembed: true);
    }
}
