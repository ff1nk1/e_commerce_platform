<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Catalog\CategoryData;
use App\DTO\Catalog\ProductData;
use App\Kafka\Saga\StockSagaMessage;
use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Services\Categories\CategoriesCache;
use App\Services\Categories\CreateCategoryService;
use App\Services\Categories\ListCategoriesService;
use App\Services\Products\DeleteProductService;
use App\Services\Products\UpdateProductService;
use App\Services\Stock\ReserveStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Junges\Kafka\Facades\Kafka;
use Tests\Concerns\UsesRedisCache;
use Tests\TestCase;

final class CatalogRedisCacheTest extends TestCase
{
    use RefreshDatabase;
    use UsesRedisCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableRedisCache();
        Kafka::fake();
    }

    public function test_get_all_categories_uses_redis_cache_and_invalidates_on_create(): void
    {
        Category::factory()->create(['name' => 'A', 'slug' => 'a']);

        $service = resolve(ListCategoriesService::class);
        $first = $service->handle();
        $this->assertCount(1, $first);

        // Mutate DB without going through service — cache should still return old data
        Category::factory()->create(['name' => 'B', 'slug' => 'b']);
        $this->assertCount(1, $service->handle());

        resolve(CreateCategoryService::class)->handle(CategoryData::fromArray(['name' => 'C', 'slug' => 'c']));
        $this->assertCount(3, $service->handle());
    }

    public function test_category_tree_ids_are_cached_and_flushed_with_categories_tag(): void
    {
        $parent = Category::factory()->create(['slug' => 'parent']);
        $child = Category::factory()->create([
            'parent_id' => $parent->id,
            'slug' => 'child',
        ]);

        $ids = $parent->getTreeIds();
        $this->assertEqualsCanonicalizing([$parent->id, $child->id], $ids);

        $this->assertTrue(Cache::tags(['categories'])->has("category_tree_ids_{$parent->id}"));

        resolve(CategoriesCache::class)->clear();

        $this->assertFalse(Cache::tags(['categories'])->has("category_tree_ids_{$parent->id}"));
    }

    public function test_product_show_cache_hit_and_invalidation_on_update(): void
    {
        $product = Product::factory()->create(['name' => 'Old Name']);

        $this->getJson("/api/catalog/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.name', 'Old Name');

        $this->assertTrue(Cache::tags(['products'])->has("product_{$product->id}"));

        // Direct DB update would leave stale cache; service update must flush
        resolve(UpdateProductService::class)->handle($product, ProductData::fromArray(['name' => 'New Name']));

        $this->assertFalse(Cache::tags(['products'])->has("product_{$product->id}"));

        $this->getJson("/api/catalog/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.name', 'New Name');
    }

    public function test_product_delete_flushes_product_cache(): void
    {
        $product = Product::factory()->create();
        $id = $product->id;

        $this->getJson("/api/catalog/products/{$id}")->assertOk();
        $this->assertTrue(Cache::tags(['products'])->has("product_{$id}"));

        resolve(DeleteProductService::class)->handle($product);

        $this->assertFalse(Cache::tags(['products'])->has("product_{$id}"));
    }

    public function test_reserve_stock_flushes_product_cache(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        Stock::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 5,
            'reserved' => 0,
        ]);

        $this->getJson("/api/catalog/products/{$product->id}")->assertOk();
        $this->assertTrue(Cache::tags(['products'])->has("product_{$product->id}"));

        resolve(ReserveStockService::class)->handle(new StockSagaMessage(
            100,
            [[
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 2,
            ]],
            (string) Str::uuid(),
        ));

        $this->assertFalse(Cache::tags(['products'])->has("product_{$product->id}"));
    }
}
