<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Junges\Kafka\Facades\Kafka;
use Tests\TestCase;

final class ProductPriceValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Kafka::fake();
    }

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

    /**
     * @return array<string, mixed>
     */
    private function payload(Store $store, Category $category, int|float|string $price): array
    {
        return [
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Item',
            'price' => $price,
        ];
    }

    public function test_store_rejects_price_above_decimal_10_2_range(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->create();

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/products', $this->payload($store, $category, 100000000))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);
    }

    public function test_store_rejects_price_with_more_than_two_decimals(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->create();

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/products', $this->payload($store, $category, '99.999'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);
    }

    public function test_store_accepts_boundary_price(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->create();

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/products', $this->payload($store, $category, '99999999.99'))
            ->assertCreated();
    }

    public function test_update_rejects_price_above_range(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/admin/products/{$product->id}", [
                'price' => 100000000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);
    }

    public function test_update_accepts_valid_price(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/admin/products/{$product->id}", [
                'price' => '199.99',
            ])
            ->assertOk();
    }
}
