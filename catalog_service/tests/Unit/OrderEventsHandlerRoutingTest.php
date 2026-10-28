<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Kafka\Handlers\OrderCreatedHandler;
use App\Kafka\Handlers\StockReleaseHandler;
use App\Kafka\MainHandlers\OrderEventsHandler;
use Junges\Kafka\Contracts\ConsumerMessage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class OrderEventsHandlerRoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_routes_order_created_to_order_created_handler(): void
    {
        /** @var ConsumerMessage&MockInterface $message */
        $message = Mockery::mock(ConsumerMessage::class);
        $message->shouldReceive('getHeaders')->andReturn(['event-type' => 'order.created']);

        $orderHandler = Mockery::mock(OrderCreatedHandler::class);
        $orderHandler->shouldReceive('__invoke')->once()->with($message);

        $this->app->instance(OrderCreatedHandler::class, $orderHandler);

        (new OrderEventsHandler)($message);
        $this->addToAssertionCount(1);
    }

    public function test_routes_stock_release_to_stock_release_handler(): void
    {
        /** @var ConsumerMessage&MockInterface $message */
        $message = Mockery::mock(ConsumerMessage::class);
        $message->shouldReceive('getHeaders')->andReturn(['event-type' => 'stock.release_requested']);

        $releaseHandler = Mockery::mock(StockReleaseHandler::class);
        $releaseHandler->shouldReceive('__invoke')->once()->with($message);

        $this->app->instance(StockReleaseHandler::class, $releaseHandler);

        (new OrderEventsHandler)($message);
        $this->addToAssertionCount(1);
    }

    public function test_ignores_unknown_event_type(): void
    {
        /** @var ConsumerMessage&MockInterface $message */
        $message = Mockery::mock(ConsumerMessage::class);
        $message->shouldReceive('getHeaders')->andReturn(['event-type' => 'something.else']);

        $orderHandler = Mockery::mock(OrderCreatedHandler::class);
        $orderHandler->shouldReceive('__invoke')->never();
        $this->app->instance(OrderCreatedHandler::class, $orderHandler);

        $releaseHandler = Mockery::mock(StockReleaseHandler::class);
        $releaseHandler->shouldReceive('__invoke')->never();
        $this->app->instance(StockReleaseHandler::class, $releaseHandler);

        (new OrderEventsHandler)($message);

        $this->addToAssertionCount(1);
    }

    public function test_ignores_missing_event_type_header(): void
    {
        /** @var ConsumerMessage&MockInterface $message */
        $message = Mockery::mock(ConsumerMessage::class);
        $message->shouldReceive('getHeaders')->andReturn([]);

        $orderHandler = Mockery::mock(OrderCreatedHandler::class);
        $orderHandler->shouldReceive('__invoke')->never();
        $this->app->instance(OrderCreatedHandler::class, $orderHandler);

        $releaseHandler = Mockery::mock(StockReleaseHandler::class);
        $releaseHandler->shouldReceive('__invoke')->never();
        $this->app->instance(StockReleaseHandler::class, $releaseHandler);

        (new OrderEventsHandler)($message);

        $this->addToAssertionCount(1);
    }
}
