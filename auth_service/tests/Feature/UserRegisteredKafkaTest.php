<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Junges\Kafka\Facades\Kafka;
use RuntimeException;
use Shop\Messaging\OutboxPublisher;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class UserRegisteredKafkaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::factory()->create([
            'name' => 'Customer',
            'slug' => 'customer',
        ]);
    }

    public function test_it_registers_user_and_publishes_to_kafka(): void
    {
        Kafka::fake();

        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ];

        $service = resolve(AuthService::class);
        $user = $service->register($userData);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);

        $this->assertTrue(Hash::check('secret123', $user->password));

        OutboxQueue::flush();
        Kafka::assertPublishedOn('user.events', null, function ($message) use ($user): bool {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'user.registered'
                && ($headers['source'] ?? null) === 'auth-service'
                && $message->getKey() === (string) $user->id
                && ($body['user_id'] ?? null) === $user->id
                && ($body['email'] ?? null) === 'john@example.com'
                && ($body['name'] ?? null) === 'John Doe'
                && ($body['event_type'] ?? null) === 'user.registered'
                && is_string($body['registered_at'] ?? null)
                && $body['registered_at'] !== '';
        });
    }

    public function test_it_rolls_back_registration_when_the_outbox_write_fails(): void
    {
        $outbox = \Mockery::mock(OutboxPublisher::class);
        $outbox->shouldReceive('publish')->once()->andThrow(new RuntimeException('outbox down'));
        $this->app->instance(OutboxPublisher::class, $outbox);

        try {
            resolve(AuthService::class)->register([
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'password' => 'secret123',
            ]);
            $this->fail('registration should roll back');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, User::query()->where('email', 'jane@example.com')->count());
    }
}
