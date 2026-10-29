<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\Kafka\Concerns\ResolvesEventType;
use App\Messaging\CategoryLifecycle;
use App\Services\SearchIndexService;
use Ecotone\Modelling\Attribute\EventHandler;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class CategoryEventsHandler
{
    use ResolvesEventType;

    public function __construct(
        private readonly SearchIndexService $index,
    ) {}

    #[EventHandler(endpointId: 'search.category')]
    public function onInbound(CategoryLifecycle $event): void
    {
        $this(new EnvelopeMessage($event->envelope));
    }

    public function __invoke(EnvelopeMessage $message): void
    {
        [$eventType, $body] = $this->resolveEventTypeAndBody($message);

        if (! in_array($eventType, ['category.created', 'category.updated', 'category.deleted'], true)) {
            Log::debug("Игнорируем неизвестное событие категории: {$eventType}");

            return;
        }

        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            Log::warning('Kafka: category event без data', [
                'event' => $eventType,
            ]);

            return;
        }

        $id = $data['id'] ?? null;
        if ($id === null) {
            Log::warning('Kafka: category event без id', [
                'event' => $eventType,
            ]);

            return;
        }

        if ($eventType === 'category.deleted') {
            $this->index->deleteCategory((int) $id);
            Log::info('Kafka: category removed from OpenSearch', ['category_id' => (int) $id]);

            return;
        }

        $hasName = ($data['name'] ?? null) !== null;
        if (! $hasName) {
            Log::warning('Kafka: category event с неполными полями', [
                'event' => $eventType,
                'category_id' => (int) $id,
            ]);

            return;
        }

        $this->index->indexCategory($data);

        if ($eventType === 'category.updated') {
            $this->index->syncCategoryNameOnProducts((int) $id, (string) $data['name']);
        }

        Log::info('Kafka: category indexed in OpenSearch', [
            'event' => $eventType,
            'category_id' => (int) $id,
        ]);
    }
}
