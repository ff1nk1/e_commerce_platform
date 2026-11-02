<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class AdminOrderAnalystGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyst_cannot_ship_order(): void
    {
        $order = $this->confirmedOrder('analyst@example.com');

        $this->withHeader('X-User-Role', 'analyst')
            ->postJson('/api/admin/orders/'.$order->id.'/ship')
            ->assertForbidden();

        $this->assertSame(0, OutboxQueue::count('order.shipped'));
    }

    public function test_analyst_can_list_orders(): void
    {
        $this->confirmedOrder('analyst-list@example.com');

        $this->withHeader('X-User-Role', 'analyst')
            ->getJson('/api/admin/orders')
            ->assertOk();
    }

    public function test_admin_can_ship_order(): void
    {
        $order = $this->confirmedOrder('admin@example.com');

        $this->withHeader('X-User-Role', 'admin')
            ->postJson('/api/admin/orders/'.$order->id.'/ship')
            ->assertOk()
            ->assertJsonPath('status', 'shipped');
    }

    public function test_request_without_role_header_is_unaffected(): void
    {
        $order = $this->confirmedOrder('internal@example.com');

        $this->postJson('/api/admin/orders/'.$order->id.'/ship')
            ->assertOk()
            ->assertJsonPath('status', 'shipped');
    }

    private function confirmedOrder(string $email): Order
    {
        ProductSnapshot::query()->create([
            'id' => 5,
            'name' => 'X',
            'price' => '10.00',
            'store_id' => 1,
        ]);

        $order = Order::query()->create([
            'saga_id' => (string) Str::uuid(),
            'user_id' => 7,
            'customer_email' => $email,
            'customer_name' => 'User',
            'status' => 'confirmed',
            'total_amount' => '10.00',
        ]);

        $order->items()->create([
            'product_id' => 5,
            'quantity' => 1,
            'unit_price' => '10.00',
        ]);

        return $order;
    }
}
