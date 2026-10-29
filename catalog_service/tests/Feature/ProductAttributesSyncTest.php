<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Junges\Kafka\Facades\Kafka;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class ProductAttributesSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_product_syncs_attributes_and_publishes_them(): void
    {
        Kafka::fake();

        $store = Store::factory()->create();
        $category = Category::factory()->create();

        $product = resolve(ProductService::class)->createProduct([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Phone',
            'price' => 199.99,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
                ['name' => 'RAM', 'value' => '8GB'],
            ],
        ]);

        $this->assertDatabaseHas('product_attributes', [
            'product_id' => $product->id,
            'name' => 'Color',
            'value' => 'Black',
        ]);
        $this->assertDatabaseHas('product_attributes', [
            'product_id' => $product->id,
            'name' => 'RAM',
            'value' => '8GB',
        ]);
        $this->assertSame(2, ProductAttribute::query()->where('product_id', $product->id)->count());

        OutboxQueue::flush();
        Kafka::assertPublishedOn('product.events', null, function ($message) use ($product): bool {
            $attrs = $message->getBody()['data']['attributes'] ?? null;
            if (! is_array($attrs)) {
                return false;
            }

            $pairs = collect($attrs)->map(fn ($a): string => ($a['name'] ?? '').':'.($a['value'] ?? ''))->all();

            return ($message->getBody()['data']['id'] ?? null) === $product->id
                && in_array('Color:Black', $pairs, true)
                && in_array('RAM:8GB', $pairs, true);
        });
    }

    public function test_update_product_replaces_attributes(): void
    {
        Kafka::fake();

        $product = Product::factory()->create();
        ProductAttribute::query()->create([
            'product_id' => $product->id,
            'name' => 'Old',
            'value' => 'Gone',
        ]);

        resolve(ProductService::class)->updateProduct($product, [
            'name' => $product->name,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Red'],
            ],
        ]);

        $this->assertDatabaseMissing('product_attributes', [
            'product_id' => $product->id,
            'name' => 'Old',
        ]);
        $this->assertDatabaseHas('product_attributes', [
            'product_id' => $product->id,
            'name' => 'Color',
            'value' => 'Red',
        ]);
        $this->assertSame(1, ProductAttribute::query()->where('product_id', $product->id)->count());

        OutboxQueue::flush();
        Kafka::assertPublishedOn('product.events', null, function ($message): bool {
            $attrs = $message->getBody()['data']['attributes'] ?? [];

            return count($attrs) === 1
                && ($attrs[0]['name'] ?? null) === 'Color'
                && ($attrs[0]['value'] ?? null) === 'Red';
        });
    }
}
