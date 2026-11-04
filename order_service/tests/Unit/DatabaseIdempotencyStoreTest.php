<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Orders\IdempotentResponse;
use App\Infrastructure\Idempotency\DatabaseIdempotencyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DatabaseIdempotencyStoreTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseIdempotencyStore $idempotency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idempotency = new DatabaseIdempotencyStore;
    }

    public function test_put_and_get_round_trip(): void
    {
        $key = 'order:idempotency:create:9:unit-key';
        $body = ['id' => 9, 'status' => 'pending'];

        $this->assertNull($this->idempotency->get($key));

        $this->idempotency->put($key, new IdempotentResponse(201, $body));

        $cached = $this->idempotency->get($key);
        $this->assertNotNull($cached);
        $this->assertSame(201, $cached->status);
        $this->assertSame($body, $cached->body);
        $this->assertDatabaseHas('idempotency_keys', ['scope_key' => $key]);
    }

    public function test_scope_key_includes_user_and_operation(): void
    {
        $this->assertSame(
            'order:idempotency:create:7:abc',
            $this->idempotency->scopeKey('create', 7, 'abc')
        );
        $this->assertSame(
            'order:idempotency:pay:7:42:abc',
            $this->idempotency->scopeKey('pay', 7, 'abc', 42)
        );
        $this->assertNotSame(
            $this->idempotency->scopeKey('create', 7, 'abc'),
            $this->idempotency->scopeKey('create', 8, 'abc')
        );
    }
}
