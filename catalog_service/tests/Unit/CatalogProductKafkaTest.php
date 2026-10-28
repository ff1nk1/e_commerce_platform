<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\CatalogService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Facades\Kafka;
use Tests\TestCase;

final class CatalogProductKafkaTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_category_publishes_kafka_event(): void
    {
        Kafka::fake();

        $category = resolve(CatalogService::class)->createCategory([
            'name' => 'Laptops',
            'slug' => 'laptops',
        ]);

        Kafka::assertPublishedOn('category.created', null, function ($message) use ($category): bool {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'category.created'
                && ($headers['source'] ?? null) === 'catalog-service'
                && ($body['event'] ?? null) === 'category.created'
                && ($body['data']['id'] ?? null) === $category->id
                && (string) $message->getKey() === (string) $category->id;
        });

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'slug' => 'laptops']);
    }

    public function test_update_category_publishes_kafka_event(): void
    {
        Kafka::fake();

        $category = Category::factory()->create(['name' => 'Old', 'slug' => 'old']);
        resolve(CatalogService::class)->updateCategory($category, ['name' => 'New']);

        Kafka::assertPublishedOn('category.updated', null, function ($message) use ($category): bool {
            $body = $message->getBody();

            return ($message->getHeaders()['event-type'] ?? null) === 'category.updated'
                && ($body['data']['id'] ?? null) === $category->id
                && ($body['data']['name'] ?? null) === 'New';
        });
    }

    public function test_delete_category_with_children_publishes_updated_and_deleted(): void
    {
        Kafka::fake();

        $parent = Category::factory()->create(['name' => 'Parent', 'slug' => 'parent']);
        $child = Category::factory()->create([
            'name' => 'Child',
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        resolve(CatalogService::class)->deleteCategory($parent);

        Kafka::assertPublishedOn('category.updated');
        Kafka::assertPublishedOn('category.deleted');

        $this->assertDatabaseMissing('categories', ['id' => $parent->id]);
        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_create_product_publishes_kafka_event(): void
    {
        Kafka::fake();

        $store = Store::factory()->create();
        $category = Category::factory()->create();

        $product = resolve(ProductService::class)->createProduct([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Phone',
            'price' => 199.99,
        ]);

        Kafka::assertPublishedOn('product.created', null, function ($message) use ($product): bool {
            $headers = $message->getHeaders();
            $body = $message->getBody();

            return ($headers['event-type'] ?? null) === 'product.created'
                && ($headers['source'] ?? null) === 'catalog-service'
                && ($body['event'] ?? null) === 'product.created'
                && isset($body['timestamp'])
                && ($body['data']['id'] ?? null) === $product->id
                && (string) $message->getKey() === (string) $product->id;
        });

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Phone']);
    }

    public function test_update_product_publishes_kafka_event(): void
    {
        Kafka::fake();

        $product = Product::factory()->create(['name' => 'Old phone']);
        resolve(ProductService::class)->updateProduct($product, ['name' => 'New phone']);

        Kafka::assertPublishedOn('product.updated', null, function ($message) use ($product): bool {
            $body = $message->getBody();

            return ($message->getHeaders()['event-type'] ?? null) === 'product.updated'
                && ($body['data']['id'] ?? null) === $product->id
                && ($body['data']['name'] ?? null) === 'New phone';
        });
    }

    public function test_delete_product_publishes_kafka_event(): void
    {
        Kafka::fake();

        $product = Product::factory()->create();
        $productId = $product->id;
        resolve(ProductService::class)->deleteProduct($product);

        Kafka::assertPublishedOn('product.deleted', null, function ($message) use ($productId): bool {
            return ($message->getBody()['data']['id'] ?? null) === $productId;
        });

        $this->assertDatabaseMissing('products', ['id' => $productId]);
    }

    public function test_create_product_succeeds_when_kafka_fails(): void
    {
        Kafka::shouldReceive('publish')
            ->once()
            ->andThrow(new \Exception('Connection to Kafka broker timed out'));

        $logSpy = Log::spy();

        $store = Store::factory()->create();
        $category = Category::factory()->create();

        $product = resolve(ProductService::class)->createProduct([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Resilient phone',
            'price' => 99,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Resilient phone',
        ]);

        $logSpy->shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message, array $context) use ($product): bool {
                return $message === 'Kafka publish failed on product.created'
                    && ($context['product_id'] ?? null) === $product->id;
            });
    }

    public function test_new_product_defaults_to_published(): void
    {
        $product = Product::factory()->create();

        $this->assertTrue($product->fresh()->is_published);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'is_published' => true,
        ]);
    }

    public function test_toggle_published_emits_unpublished_then_published(): void
    {
        Kafka::fake();

        $product = Product::factory()->create([
            'name' => 'AI Phone',
            'description' => 'A phone for embeddings',
            'is_published' => true,
        ]);
        $product->attributes()->create([
            'name' => 'Color',
            'value' => 'Blue',
        ]);

        $unpublished = resolve(ProductService::class)->togglePublished($product);

        $this->assertFalse($unpublished->is_published);
        Kafka::assertPublishedOn('product.unpublished', null, function ($message) use ($product): bool {
            $headers = $message->getHeaders();
            $body = $message->getBody();
            $data = $body['data'] ?? [];
            $rawAttributes = is_array($data) && is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
            /** @var array<int, array<string, mixed>> $attributes */
            $attributes = $rawAttributes;

            return ($headers['event-type'] ?? null) === 'product.unpublished'
                && ($headers['source'] ?? null) === 'catalog-service'
                && ($body['event'] ?? null) === 'product.unpublished'
                && ($data['id'] ?? null) === $product->id
                && ($data['is_published'] ?? null) === false
                && ($data['name'] ?? null) === 'AI Phone'
                && ($data['description'] ?? null) === 'A phone for embeddings'
                && ($data['store_id'] ?? null) === $product->store_id
                && collect($attributes)->contains(
                    fn (array $attr): bool => ($attr['name'] ?? null) === 'Color'
                        && ($attr['value'] ?? null) === 'Blue'
                )
                && (string) $message->getKey() === (string) $product->id;
        });

        $published = resolve(ProductService::class)->togglePublished($unpublished);

        $this->assertTrue($published->is_published);
        Kafka::assertPublishedOn('product.published', null, function ($message) use ($product): bool {
            $body = $message->getBody();
            $data = $body['data'] ?? [];

            return ($message->getHeaders()['event-type'] ?? null) === 'product.published'
                && ($body['event'] ?? null) === 'product.published'
                && ($data['id'] ?? null) === $product->id
                && ($data['is_published'] ?? null) === true;
        });
    }
}
