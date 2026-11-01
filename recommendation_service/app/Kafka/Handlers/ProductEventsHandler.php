<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Enums\CatalogEventType;
use App\Messaging\ProductLifecycle;
use App\Models\ProductCatalog;
use App\Services\Catalog\RemoveProductService;
use App\Services\Catalog\SetProductPublishedService;
use App\Services\Catalog\UpsertProductService;
use Ecotone\Modelling\Attribute\EventHandler;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class ProductEventsHandler
{
    public const ALLOWED_TYPES = [
        CatalogEventType::ProductCreated->value,
        CatalogEventType::ProductUpdated->value,
        CatalogEventType::ProductDeleted->value,
        CatalogEventType::ProductPublished->value,
        CatalogEventType::ProductUnpublished->value,
    ];

    public function __construct(
        private readonly UpsertProductService $upsert,
        private readonly RemoveProductService $remove,
        private readonly SetProductPublishedService $setPublished,
    ) {}

    #[EventHandler(endpointId: 'recommendation.product')]
    public function onInbound(ProductLifecycle $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        $headers = $message->getHeaders();
        $body = $message->getBody();

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
        $isUpsertEvent = in_array($eventType, [
            CatalogEventType::ProductCreated->value,
            CatalogEventType::ProductUpdated->value,
        ], true);

        if ($isUpsertEvent && ! $this->hasUpsertPayload($data)) {
            Log::warning('Recommendation: product upsert missing required fields', [
                'event' => $eventType,
                'product_id' => $productId,
                'data_keys' => array_keys($data),
            ]);

            return;
        }

        match ($eventType) {
            CatalogEventType::ProductDeleted->value => $this->remove->handle($productId),
            CatalogEventType::ProductPublished->value => $this->handlePublished($data, $productId),
            CatalogEventType::ProductUnpublished->value => $this->setPublished->handle($productId, false),
            default => $this->upsert->handle($data, reembed: true),
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
            $this->setPublished->handle($productId, true);

            return;
        }

        if (! $this->hasUpsertPayload($data) && ! $existing) {
            Log::warning('Recommendation: product.published for unknown product without name', [
                'product_id' => $productId,
            ]);

            return;
        }

        $this->upsert->handle($data, reembed: true);
    }
}
