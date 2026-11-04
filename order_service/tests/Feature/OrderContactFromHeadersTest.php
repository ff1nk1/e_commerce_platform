<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BindsInMemoryIdempotency;
use Tests\TestCase;

/**
 * Buyer contact (email/name) travels only in the trusted gateway headers
 * (see App\Http\Middleware\ResolveGatewayIdentity). The frontend posts only
 * items, so the contact must be resolved from X-User-Email / X-User-Name.
 */
final class OrderContactFromHeadersTest extends TestCase
{
    use BindsInMemoryIdempotency;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindInMemoryIdempotency();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function seedSnapshot(): void
    {
        ProductSnapshot::query()->create([
            'id' => 77,
            'name' => 'Headphones',
            'price' => '50.00',
            'store_id' => 1,
        ]);
    }

    public function test_order_created_from_gateway_headers_without_body_contact(): void
    {
        $this->seedSnapshot();

        $response = $this->postJson(
            '/api/orders',
            [
                'items' => [['product_id' => 77, 'quantity' => 1]],
            ],
            [
                'Idempotency-Key' => 'header-only-contact',
                'X-User-Id' => '3',
                'X-User-Role' => 'user',
                'X-User-Email' => 'buyer@example.com',
                'X-User-Name' => 'Buyer From Header',
            ],
        );

        $response->assertCreated();

        $this->assertDatabaseHas('orders', [
            'user_id' => 3,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer From Header',
        ]);
    }

    public function test_gateway_headers_override_forged_body_contact(): void
    {
        $this->seedSnapshot();

        $response = $this->postJson(
            '/api/orders',
            [
                'user_id' => 999,
                'email' => 'attacker@example.com',
                'name' => 'Attacker',
                'items' => [['product_id' => 77, 'quantity' => 1]],
            ],
            [
                'Idempotency-Key' => 'header-overrides-body',
                'X-User-Id' => '3',
                'X-User-Role' => 'user',
                'X-User-Email' => 'buyer@example.com',
                'X-User-Name' => 'Buyer From Header',
            ],
        );

        $response->assertCreated();

        $this->assertDatabaseHas('orders', [
            'user_id' => 3,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer From Header',
        ]);
        $this->assertDatabaseMissing('orders', [
            'customer_email' => 'attacker@example.com',
        ]);
    }

    public function test_order_created_from_body_when_no_gateway_headers(): void
    {
        $this->seedSnapshot();

        $response = $this->postJson(
            '/api/orders',
            [
                'user_id' => 3,
                'email' => 'internal@example.com',
                'name' => 'Internal Caller',
                'items' => [['product_id' => 77, 'quantity' => 1]],
            ],
            ['Idempotency-Key' => 'body-only-contact'],
        );

        $response->assertCreated();

        $this->assertDatabaseHas('orders', [
            'user_id' => 3,
            'customer_email' => 'internal@example.com',
            'customer_name' => 'Internal Caller',
        ]);
    }

    public function test_order_rejected_without_any_contact(): void
    {
        $this->seedSnapshot();

        $response = $this->postJson(
            '/api/orders',
            [
                'items' => [['product_id' => 77, 'quantity' => 1]],
            ],
            ['Idempotency-Key' => 'missing-contact'],
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'name']);
    }
}
