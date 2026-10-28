<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Facades\Kafka;
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

    public function test_it_registers_user_even_if_kafka_fails(): void
    {
        Kafka::shouldReceive('publish')
            ->once()
            ->andThrow(new \Exception('Connection to Kafka broker timed out'));

        $logSpy = Log::spy();

        $userData = [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret123',
        ];

        $service = resolve(AuthService::class);
        $user = $service->register($userData);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ]);

        $logSpy->shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message, array $context) use ($user): bool {
                return $message === 'Kafka publish failed on user.registered'
                    && isset($context['user_id']) && $context['user_id'] === $user->id
                    && isset($context['error']) && $context['error'] === 'Connection to Kafka broker timed out';
            });
    }
}
