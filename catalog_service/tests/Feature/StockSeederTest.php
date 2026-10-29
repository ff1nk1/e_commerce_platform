<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use Database\Seeders\StockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class StockSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_seeder_emits_stock_changed_outbox_events(): void
    {
        $store = Store::factory()->create();
        $first = Product::factory()->create(['store_id' => $store->id]);
        $second = Product::factory()->create(['store_id' => $store->id]);

        $this->seed(StockSeeder::class);

        $this->assertSame(2, Stock::query()->count());
        $this->assertSame(0, (int) Stock::query()->where('product_id', $first->id)->value('quantity'));
        $this->assertSame(100, (int) Stock::query()->where('product_id', $second->id)->value('quantity'));

        $events = OutboxQueue::ofType('stock.changed');

        $this->assertCount(2, $events);
        $this->assertSame('catalog.events', $events[0]->topic);
        $this->assertSame($first->id, (int) $events[0]->payload['product_id']);
        $this->assertSame(0, (int) $events[0]->payload['quantity']);
        $this->assertSame($second->id, (int) $events[1]->payload['product_id']);
        $this->assertSame(100, (int) $events[1]->payload['quantity']);
    }
}
