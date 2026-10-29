<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Kafka\Handlers\StockEventsHandler;
use App\Messaging\CatalogStockChanged;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use OpenSearch\Namespaces\IndicesNamespace;
use Shop\Messaging\InboundEnvelope;
use Tests\TestCase;

final class StockEventsHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_stock_changed_updates_in_stock_true(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('exists')->once()->andReturn(true);
        $client->shouldReceive('update')->once()->with(Mockery::on(function (array $params): bool {
            $this->assertSame('products', $params['index']);
            $this->assertSame('42', $params['id']);
            $this->assertSame([
                'quantity' => 5,
                'in_stock' => true,
            ], $params['body']['doc']);

            return true;
        }))->andReturn(['result' => 'updated']);

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('stock.changed', [
            'product_id' => 42,
            'store_id' => 7,
            'quantity' => 5,
        ]);

        $this->app->make(StockEventsHandler::class)->onInbound($message);
    }

    public function test_stock_changed_updates_in_stock_false(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('exists')->once()->andReturn(true);
        $client->shouldReceive('update')->once()->with(Mockery::on(function (array $params): bool {
            $this->assertSame([
                'quantity' => 0,
                'in_stock' => false,
            ], $params['body']['doc']);

            return true;
        }))->andReturn(['result' => 'updated']);

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('stock.changed', [
            'product_id' => 42,
            'store_id' => 7,
            'quantity' => 0,
        ]);

        $this->app->make(StockEventsHandler::class)->onInbound($message);
    }

    public function test_stock_changed_skips_missing_product(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('exists')->once()->andReturn(false);
        $client->shouldNotReceive('update');

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('stock.changed', [
            'product_id' => 99,
            'store_id' => 1,
            'quantity' => 3,
        ]);

        $this->app->make(StockEventsHandler::class)->onInbound($message);
    }

    public function test_non_stock_changed_events_are_ignored(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('exists');
        $client->shouldNotReceive('update');
        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('stock.reserved', [
            'order_id' => 1,
            'status' => 'success',
        ]);

        $this->app->make(StockEventsHandler::class)->onInbound($message);
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list', ['namespace' => 'kafka'])
            ->expectsOutputToContain('kafka:consume:stock-events')
            ->assertSuccessful();
    }

    private function mockClientWithExistingIndices(): Client&MockInterface
    {
        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->andReturn(true);

        /** @var Client&MockInterface $client */
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);

        return $client;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function consumerMessage(?string $eventType, array $body): CatalogStockChanged
    {
        $headers = $eventType === null ? [] : ['event-type' => $eventType];

        return new CatalogStockChanged(new InboundEnvelope(
            eventType: $eventType ?? '',
            messageId: 'test-'.($eventType ?? 'none'),
            topic: 'stock.events',
            key: null,
            sagaId: null,
            payload: $body,
            headers: $headers,
        ));
    }
}
