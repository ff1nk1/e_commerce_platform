<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogHttpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'X-User-Id' => '42',
            'X-User-Role' => 'admin',
            'X-User-Name' => 'Yan Admin',
            'X-User-Email' => 'yan@example.com',
        ];
    }

    public function test_list_stores(): void
    {
        Store::factory()->create(['name' => 'Alpha Shop']);
        Store::factory()->create(['name' => 'Beta Shop']);

        $response = $this->getJson('/api/catalog/stores');

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['name' => 'Alpha Shop']);
    }

    public function test_store_products_returns_store_meta_and_supports_in_stock_filter(): void
    {
        $store = Store::factory()->create(['name' => 'Main Store']);
        $category = Category::factory()->create();
        $inStock = Product::factory()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'In stock item',
        ]);
        $outOfStock = Product::factory()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Out of stock item',
        ]);

        Stock::factory()->create([
            'product_id' => $inStock->id,
            'store_id' => $store->id,
            'quantity' => 5,
            'reserved' => 0,
        ]);
        Stock::factory()->create([
            'product_id' => $outOfStock->id,
            'store_id' => $store->id,
            'quantity' => 2,
            'reserved' => 2,
        ]);

        $all = $this->getJson("/api/catalog/stores/{$store->id}/products");
        $all->assertOk()
            ->assertJsonPath('store.name', 'Main Store')
            ->assertJsonPath('products.total', 2);

        $filtered = $this->getJson("/api/catalog/stores/{$store->id}/products?status=in_stock");
        $filtered->assertOk()
            ->assertJsonPath('products.total', 1)
            ->assertJsonFragment(['name' => 'In stock item'])
            ->assertJsonMissing(['name' => 'Out of stock item']);
    }

    public function test_admin_products_rejects_without_gateway_headers(): void
    {
        $this->getJson('/api/admin/products')->assertForbidden();
    }

    public function test_admin_products_rejects_non_admin_role(): void
    {
        $this->withHeaders([
            'X-User-Id' => '1',
            'X-User-Role' => 'customer',
        ])->getJson('/api/admin/products')->assertForbidden();
    }

    public function test_get_upload_url_rejects_without_gateway_headers(): void
    {
        $this->postJson('/api/get-upload-url', [
            'filename' => 'photo.jpg',
            'content_type' => 'image/jpeg',
        ])->assertForbidden();
    }

    public function test_admin_products_can_filter_by_store_id(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        Product::factory()->create(['store_id' => $storeA->id, 'name' => 'A only']);
        Product::factory()->create(['store_id' => $storeB->id, 'name' => 'B only']);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/products?store_id='.$storeA->id);

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['name' => 'A only'])
            ->assertJsonMissing(['name' => 'B only']);
    }

    public function test_admin_stocks_list_create_and_update(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);

        $create = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/stocks', [
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 12,
        ]);

        $create->assertCreated()
            ->assertJsonPath('stock.quantity', 12)
            ->assertJsonPath('stock.reserved', 0)
            ->assertJsonPath('stock.available', 12);

        $stockId = $create->json('stock.id');
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'stock.changed',
        ]);

        $list = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/stocks?store_id='.$store->id);
        $list->assertOk()->assertJsonPath('total', 1);

        $update = $this->withHeaders($this->adminHeaders())->patchJson('/api/admin/stocks/'.$stockId, [
            'quantity' => 20,
        ]);

        $update->assertOk()
            ->assertJsonPath('stock.quantity', 20)
            ->assertJsonPath('stock.available', 20);

        $this->assertSame(2, OutboxEvent::query()->where('event_type', 'stock.changed')->count());
    }
}
