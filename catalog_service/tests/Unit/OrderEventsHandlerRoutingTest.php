<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Kafka\Handlers\OrderCreatedHandler;
use App\Kafka\Handlers\StockCommitHandler;
use App\Kafka\Handlers\StockReleaseHandler;
use App\Messaging\CatalogInboundEventFactory;
use App\Messaging\OrderCreatedMessage;
use App\Messaging\StockCommitRequested;
use App\Messaging\StockReleaseRequested;
use Shop\Messaging\InboundEnvelope;
use Tests\TestCase;

final class OrderEventsHandlerRoutingTest extends TestCase
{
    public function test_routes_order_created_to_order_created_handler(): void
    {
        $event = $this->event('order.created');

        $this->assertInstanceOf(OrderCreatedMessage::class, $event);
        resolve(OrderCreatedHandler::class)->onInbound($event);
    }

    public function test_routes_stock_release_to_stock_release_handler(): void
    {
        $event = $this->event('stock.release_requested');

        $this->assertInstanceOf(StockReleaseRequested::class, $event);
        resolve(StockReleaseHandler::class)->onInbound($event);
    }

    public function test_routes_stock_commit_to_stock_commit_handler(): void
    {
        $event = $this->event('stock.commit_requested');

        $this->assertInstanceOf(StockCommitRequested::class, $event);
        resolve(StockCommitHandler::class)->onInbound($event);
    }

    public function test_ignores_unknown_event_type(): void
    {
        $this->assertNull($this->event('something.else'));
    }

    public function test_ignores_missing_event_type_header(): void
    {
        $this->assertNull($this->event(''));
    }

    private function event(string $eventType): ?object
    {
        return (new CatalogInboundEventFactory)->make(new InboundEnvelope(
            eventType: $eventType,
            messageId: 'test-'.$eventType,
            topic: 'order.events',
            key: null,
            sagaId: null,
            payload: [],
            headers: $eventType === '' ? [] : ['event-type' => $eventType],
        ));
    }
}
