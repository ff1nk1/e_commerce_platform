<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Junges\Kafka\Consumers\Builder;
use Junges\Kafka\Contracts\MessageConsumer;
use Junges\Kafka\Facades\Kafka;
use Mockery;
use Tests\TestCase;

final class KafkaConsumeCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockConsoleOutput = false;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_order_events_consume_returns_failure_on_kafka_error(): void
    {
        $this->mockFailingConsumer();

        $exitCode = Artisan::call('kafka:consume:order-events');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ОШИБКА KAFKA', Artisan::output());
    }

    public function test_user_events_consume_returns_failure_on_kafka_error(): void
    {
        $this->mockFailingConsumer();

        $exitCode = Artisan::call('kafka:consume:user-events');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ОШИБКА KAFKA', Artisan::output());
    }

    private function mockFailingConsumer(): void
    {
        $consumer = Mockery::mock(MessageConsumer::class);
        $consumer->shouldReceive('consume')
            ->once()
            ->andThrow(new \RuntimeException('broker unreachable'));

        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('withBrokers')->andReturnSelf();
        $builder->shouldReceive('withConsumerGroupId')->andReturnSelf();
        $builder->shouldReceive('withHandler')->andReturnSelf();
        $builder->shouldReceive('withAutoCommit')->andReturnSelf();
        $builder->shouldReceive('withOptions')->andReturnSelf();
        $builder->shouldReceive('build')->andReturn($consumer);

        Kafka::shouldReceive('consumer')->once()->andReturn($builder);
    }
}
