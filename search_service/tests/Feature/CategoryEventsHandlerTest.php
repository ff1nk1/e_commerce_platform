<?php

namespace Tests\Feature;

use App\Kafka\Handlers\CategoryEventsHandler;
use Junges\Kafka\Contracts\ConsumerMessage;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use OpenSearch\Namespaces\IndicesNamespace;
use Tests\TestCase;

class CategoryEventsHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_category_created_indexes_document(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('index')->once()->with(Mockery::on(function (array $params): bool {
            $this->assertSame('categories', $params['index']);
            $this->assertSame('3', $params['id']);
            $this->assertSame([
                'id' => 3,
                'parent_id' => null,
                'name' => 'Gadgets',
                'slug' => 'gadgets',
            ], $params['body']);

            return true;
        }))->andReturn(['result' => 'created']);
        $client->shouldNotReceive('updateByQuery');

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('category.created', [
            'event' => 'category.created',
            'data' => [
                'id' => 3,
                'parent_id' => null,
                'name' => 'Gadgets',
                'slug' => 'gadgets',
            ],
        ]);

        $this->app->make(CategoryEventsHandler::class)($message);
    }

    public function test_category_updated_syncs_product_category_name(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('index')->once()->andReturn(['result' => 'updated']);
        $client->shouldReceive('updateByQuery')->once()->with(Mockery::on(function (array $params): bool {
            $this->assertSame('products', $params['index']);
            $this->assertSame(3, $params['body']['query']['term']['category_id']);
            $this->assertSame('New Gadgets', $params['body']['script']['params']['category_name']);

            return true;
        }))->andReturn(['updated' => 2]);

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('category.updated', [
            'event' => 'category.updated',
            'data' => [
                'id' => 3,
                'parent_id' => null,
                'name' => 'New Gadgets',
                'slug' => 'gadgets',
            ],
        ]);

        $this->app->make(CategoryEventsHandler::class)($message);
    }

    public function test_category_deleted_removes_document(): void
    {
        $client = $this->mockClientWithExistingIndices();
        $client->shouldReceive('delete')->once()->with(Mockery::on(function (array $params): bool {
            return $params['index'] === 'categories' && $params['id'] === '3';
        }))->andReturn(['result' => 'deleted']);

        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('category.deleted', [
            'event' => 'category.deleted',
            'data' => [
                'id' => 3,
                'name' => 'Gadgets',
                'slug' => 'gadgets',
            ],
        ]);

        $this->app->make(CategoryEventsHandler::class)($message);
    }

    public function test_unknown_event_type_is_ignored(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('index');
        $client->shouldNotReceive('delete');
        $client->shouldNotReceive('updateByQuery');
        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('category.moved', [
            'event' => 'category.moved',
            'data' => ['id' => 1, 'name' => 'X'],
        ]);

        $this->app->make(CategoryEventsHandler::class)($message);
    }

    public function test_category_updated_without_name_is_skipped(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('index');
        $client->shouldNotReceive('updateByQuery');
        $this->app->instance(Client::class, $client);

        $message = $this->consumerMessage('category.updated', [
            'event' => 'category.updated',
            'data' => [
                'id' => 3,
                'slug' => 'gadgets',
            ],
        ]);

        $this->app->make(CategoryEventsHandler::class)($message);
    }

    public function test_missing_data_is_skipped(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('index');
        $client->shouldNotReceive('delete');
        $this->app->instance(Client::class, $client);

        $this->app->make(CategoryEventsHandler::class)(
            $this->consumerMessage('category.created', ['event' => 'category.created'])
        );
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list', ['namespace' => 'kafka'])
            ->expectsOutputToContain('kafka:consume:category-events')
            ->assertSuccessful();
    }

    private function mockClientWithExistingIndices(): Client&MockInterface
    {
        $indices = Mockery::mock(IndicesNamespace::class);
        $indices->shouldReceive('exists')->andReturn(true);

        /** @var Client&MockInterface $client */
        /** @var Client&MockInterface $client */
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('indices')->andReturn($indices);

        return $client;
    }

    private function consumerMessage(?string $eventType, array $body): ConsumerMessage&MockInterface
    {
        $headers = $eventType === null ? [] : ['event-type' => $eventType];

        /** @var ConsumerMessage&MockInterface $message */
        $message = Mockery::mock(ConsumerMessage::class);
        $message->shouldReceive('getHeaders')->andReturn($headers);
        $message->shouldReceive('getBody')->andReturn($body);

        return $message;
    }
}
