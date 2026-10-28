<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\CatalogService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $service = resolve(CatalogService::class);
        $first = $service->getAllCategories();
        $this->assertCount(1, $first);

        // Mutate DB without going through service — cache should still return old data
        Category::factory()->create(['name' => 'B', 'slug' => 'b']);
        $this->assertCount(1, $service->getAllCategories());

        $service->createCategory(['name' => 'C', 'slug' => 'c']);
        $this->assertCount(3, $service->getAllCategories());
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

        resolve(CatalogService::class)->clearCategoryCache();

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
        resolve(ProductService::class)->updateProduct($product, ['name' => 'New Name']);

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

        resolve(ProductService::class)->deleteProduct($product);

        $this->assertFalse(Cache::tags(['products'])->has("product_{$id}"));
    }
}
