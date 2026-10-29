<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Shop\Messaging\KafkaConsumerLoop;
use Shop\Messaging\Topics;
use Tests\TestCase;

final class KafkaConsumeCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockConsoleOutput = false;
    }

    public function test_order_events_consume_returns_failure_on_kafka_error(): void
    {
        $this->bindFailingLoop([Topics::ORDER_EVENTS], 'notification-service-order-v1');

        $exitCode = Artisan::call('kafka:consume:order-events');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ОШИБКА KAFKA', Artisan::output());
    }

    public function test_user_events_consume_returns_failure_on_kafka_error(): void
    {
        $this->bindFailingLoop([Topics::USER_EVENTS], 'notification-service-consumers-v1');

        $exitCode = Artisan::call('kafka:consume:user-events');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ОШИБКА KAFKA', Artisan::output());
    }

    /**
     * @param  list<string>  $topics
     */
    private function bindFailingLoop(array $topics, string $group): void
    {
        $this->app->instance(KafkaConsumerLoop::class, new class($topics, $group) implements KafkaConsumerLoop
        {
            /**
             * @param  list<string>  $expectedTopics
             */
            public function __construct(
                private readonly array $expectedTopics,
                private readonly string $expectedGroup,
            ) {}

            public function consume(array $topics, string $groupId, string $offsetReset, callable $handler): void
            {
                throw_if($topics !== $this->expectedTopics || $groupId !== $this->expectedGroup || $offsetReset === '', RuntimeException::class, 'unexpected consumer arguments');

                throw new RuntimeException('broker unreachable');
            }
        });
    }
}
