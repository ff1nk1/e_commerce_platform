<?php

namespace Tests\Feature;

use App\Kafka\Handlers\ProductEventsHandler;
use Junges\Kafka\Contracts\ConsumerMessage;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use OpenSearch\Namespaces\IndicesNamespace;
use Tests\TestCase;

class ProductEventsHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_product_created_indexes_document(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('index')->once()->with(Mockery::on(function (array $params): bool {
            $this->assertSame('products', $params['index']);
            $this->assertSame('42', $params['id']);
            $this->assertSame(42, $params['body']['id']);
            $this->assertSame('Phone', $params['body']['name']);
            $this->assertSame(7, $params['body']['store_id']);
            $this->assertSame(3, $params['body']['category_id']);
            $this->assertSame('Gadgets', $params['body']['category_name']);
            $this->assertSame('Main', $params['body']['store_name']);
            $this->assertSame(0, $params['body']['quantity']);
            $this->assertFalse($params['body']['in_stock']);
            $this->assertSame(199.99, $params['body']['price']);
            $this->assertSame([
                ['name' => 'Color', 'value' => 'Black'],
            ], $params['body']['attributes']);

            return true;
        }))->andReturn(['result' => 'created']);

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('product.created', [
            'event' => 'product.created',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'id' => 42,
                'name' => 'Phone',
                'price' => '199.99',
                'store_id' => 7,
                'category_id' => 3,
                'description' => 'A phone',
                'category' => ['id' => 3, 'name' => 'Gadgets'],
                'store' => ['id' => 7, 'name' => 'Main'],
                'attributes' => [
                    ['name' => 'Color', 'value' => 'Black'],
                ],
            ],
        ]);

        $this->app->make(ProductEventsHandler::class)($message);
    }

    public function test_product_updated_preserves_stock(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('exists')->once()->andReturn(true);
        $client->shouldReceive('update')->once()->with(Mockery::on(function (array $params): bool {
            $doc = $params['body']['doc'] ?? [];
            $this->assertSame('New phone', $doc['name']);
            $this->assertArrayNotHasKey('quantity', $doc);
            $this->assertArrayNotHasKey('in_stock', $doc);

            return $params['index'] === 'products' && $params['id'] === '42';
        }))->andReturn(['result' => 'updated']);
        $client->shouldNotReceive('index');

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('product.updated', [
            'event' => 'product.updated',
            'data' => [
                'id' => 42,
                'name' => 'New phone',
                'price' => '249.50',
                'store_id' => 9,
            ],
        ]);

        $this->app->make(ProductEventsHandler::class)($message);
    }

    public function test_product_deleted_removes_document(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('delete')->once()->with(Mockery::on(function (array $params): bool {
            return $params['index'] === 'products' && $params['id'] === '42';
        }))->andReturn(['result' => 'deleted']);

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('product.deleted', [
            'event' => 'product.deleted',
            'data' => [
                'id' => 42,
                'name' => 'Gone',
                'price' => '10.00',
                'store_id' => 1,
            ],
        ]);

        $this->app->make(ProductEventsHandler::class)($message);
    }

    public function test_falls_back_to_body_event_when_header_missing(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('index')->once()->andReturn(['result' => 'created']);

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage(null, [
            'event' => 'product.created',
            'data' => [
                'id' => 5,
                'name' => 'From body event',
                'price' => '55.00',
                'store_id' => 1,
            ],
        ]);

        $this->app->make(ProductEventsHandler::class)($message);
    }

    public function test_invalid_payload_is_skipped(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('index');
        $client->shouldNotReceive('delete');
        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('product.created', [
            'event' => 'product.created',
            'data' => [
                'id' => 1,
                'name' => 'Incomplete',
            ],
        ]);

        $this->app->make(ProductEventsHandler::class)($message);
    }

    public function test_unknown_event_type_is_ignored(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('index');
        $client->shouldNotReceive('delete');
        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('product.archived', [
            'event' => 'product.archived',
            'data' => ['id' => 1, 'name' => 'X', 'store_id' => 1],
        ]);

        $this->app->make(ProductEventsHandler::class)($message);
    }

    public function test_missing_data_or_id_is_skipped(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('index');
        $client->shouldNotReceive('delete');
        $this->app->instance(Client::class, $client);

        $this->app->make(ProductEventsHandler::class)(
            $this->consumerMessage('product.created', ['event' => 'product.created'])
        );

        $this->app->make(ProductEventsHandler::class)(
            $this->consumerMessage('product.deleted', [
                'event' => 'product.deleted',
                'data' => ['name' => 'No id'],
            ])
        );
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list', ['namespace' => 'kafka'])
            ->expectsOutputToContain('kafka:consume:product-events')
            ->assertSuccessful();
    }

    private function mockClientWithExistingIndices(): Client&MockInterface
    {
        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->andReturn(true);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);

        return $client;
    }

    private function consumerMessage(?string $eventType, array $body): ConsumerMessage
    {
        $headers = $eventType === null ? [] : ['event-type' => $eventType];

        $message = Mockery::mock(ConsumerMessage::class);
        $message->shouldReceive('getHeaders')->andReturn($headers);
        $message->shouldReceive('getBody')->andReturn($body);

        return $message;
    }
}
