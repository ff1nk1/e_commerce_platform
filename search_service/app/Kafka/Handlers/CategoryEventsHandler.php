<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\DTO\Search\CategoryDocument;
use App\Enums\SearchEventType;
use App\Kafka\Concerns\ResolvesEventType;
use App\Messaging\CategoryLifecycle;
use App\Services\Indexing\CategoryIndexer;
use Ecotone\Modelling\Attribute\EventHandler;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class CategoryEventsHandler
{
    use ResolvesEventType;

    public function __construct(
        private readonly CategoryIndexer $categories,
    ) {}

    #[EventHandler(endpointId: 'search.category')]
    public function onInbound(CategoryLifecycle $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        [$eventTypeRaw, $body] = $this->resolveEventTypeAndBody($message);
        $eventType = is_string($eventTypeRaw) ? SearchEventType::tryFrom($eventTypeRaw) : null;

        if ($eventType === null || ! $eventType->isCategory()) {
            Log::debug("Игнорируем неизвестное событие категории: {$eventTypeRaw}");

            return;
        }

        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            Log::warning('Kafka: category event без data', [
                'event' => $eventType->value,
            ]);

            return;
        }

        $id = $data['id'] ?? null;
        if ($id === null) {
            Log::warning('Kafka: category event без id', [
                'event' => $eventType->value,
            ]);

            return;
        }

        /** @var array<string, mixed> $data */
        $document = CategoryDocument::fromArray($data);

        if ($eventType === SearchEventType::CategoryDeleted) {
            $this->categories->delete($document->id);
            Log::info('Kafka: category removed from OpenSearch', ['category_id' => $document->id]);

            return;
        }

        $hasName = ($data['name'] ?? null) !== null;
        if (! $hasName) {
            Log::warning('Kafka: category event с неполными полями', [
                'event' => $eventType->value,
                'category_id' => $document->id,
            ]);

            return;
        }

        $this->categories->upsert($document);

        if ($eventType === SearchEventType::CategoryUpdated) {
            $this->categories->syncNameOnProducts($document->id, $document->name);
        }

        Log::info('Kafka: category indexed in OpenSearch', [
            'event' => $eventType->value,
            'category_id' => $document->id,
        ]);
    }
}
