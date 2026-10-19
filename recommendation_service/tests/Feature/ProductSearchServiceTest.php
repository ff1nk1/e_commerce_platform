<?php

namespace Tests\Feature;

use App\Models\ProductCatalog;
use App\Services\Catalog\DTOs\SearchHit;
use App\Services\Catalog\ProductSearchService;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\SearchFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class ProductSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductSearchService $search;

    /** @var list<float> */
    private array $queryVector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(LlmClient::class, new FakeLlmClient);
        $this->search = app(ProductSearchService::class);
        $this->queryVector = array_fill(0, 768, 0.01);
    }

    public function test_excludes_unpublished_and_null_embedding(): void
    {
        $this->insertProduct(1, 'Visible', embedding: true, published: true);
        $this->insertProduct(2, 'Draft', embedding: true, published: false);
        $this->insertProduct(3, 'No Embed', embedding: false, published: true);

        $results = $this->search->search(
            new SearchFilters(query: 'Visible'),
            limit: 10,
            precomputedVector: $this->queryVector,
        );

        $ids = array_map(static fn (SearchHit $h): int => $h->product->product_id, $results);
        $this->assertSame([1], $ids);
        $this->assertInstanceOf(SearchHit::class, $results[0]);
        $this->assertIsFloat($results[0]->distance);
    }

    public function test_filters_by_store_category_stock_price_and_soft_attributes(): void
    {
        $this->insertProduct(10, 'A', storeId: 3, storeName: 'ТЦ Галерея', categoryName: 'Джинсы', price: 100, inStock: true, attributes: [
            ['name' => 'Цвет', 'value' => 'Чёрный'],
        ]);
        $this->insertProduct(11, 'B', storeId: 1, storeName: 'Другой магазин', categoryName: 'Джинсы', price: 100, inStock: true, attributes: [
            ['name' => 'Цвет', 'value' => 'Чёрный'],
        ]);
        $this->insertProduct(12, 'C', storeId: 3, storeName: 'ТЦ Галерея', categoryName: 'Футболки', price: 100, inStock: true, attributes: [
            ['name' => 'Цвет', 'value' => 'Чёрный'],
        ]);
        $this->insertProduct(13, 'D', storeId: 3, storeName: 'ТЦ Галерея', categoryName: 'Джинсы', price: 100, inStock: false, attributes: [
            ['name' => 'Цвет', 'value' => 'Чёрный'],
        ]);
        $this->insertProduct(14, 'E', storeId: 3, storeName: 'ТЦ Галерея', categoryName: 'Джинсы', price: 500, inStock: true, attributes: [
            ['name' => 'Цвет', 'value' => 'Чёрный'],
        ]);
        $this->insertProduct(15, 'F', storeId: 3, storeName: 'ТЦ Галерея', categoryName: 'Джинсы', price: 100, inStock: true, attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
        ]);

        $results = $this->search->search(
            new SearchFilters(
                storeName: 'галерея',
                categoryName: 'джинсы',
                inStock: true,
                priceMin: 50,
                priceMax: 200,
                query: 'item',
                attributes: [['name' => 'цвет', 'value' => 'черного']],
            ),
            limit: 10,
            precomputedVector: $this->queryVector,
        );

        $ids = array_map(static fn (SearchHit $h): int => $h->product->product_id, $results);
        // Soft attribute re-rank: black jeans (10) before blue (15); hard filters still exclude others.
        $this->assertSame(10, $ids[0] ?? null);
        $this->assertContains(15, $ids);
        $this->assertNotContains(11, $ids);
        $this->assertNotContains(12, $ids);
        $this->assertNotContains(13, $ids);
        $this->assertNotContains(14, $ids);
    }

    public function test_soft_reranks_color_ahead_of_size_mismatch(): void
    {
        $this->insertProduct(30, 'Black28', attributes: [
            ['name' => 'Цвет', 'value' => 'Чёрный'],
            ['name' => 'Размер', 'value' => '28'],
        ]);
        $this->insertProduct(31, 'Blue32', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
            ['name' => 'Размер', 'value' => '32'],
        ]);

        // Soft color only — size not required, so both remain; black ranks first.
        $results = $this->search->search(
            new SearchFilters(
                query: 'джинсы',
                attributes: [
                    ['name' => 'Цвет', 'value' => 'чёрный'],
                ],
            ),
            limit: 5,
            precomputedVector: $this->queryVector,
        );

        $ids = array_map(static fn (SearchHit $h): int => $h->product->product_id, $results);
        $this->assertSame(30, $ids[0] ?? null);
        $this->assertContains(31, $ids);
    }

    public function test_hard_size_drops_mismatches_and_keeps_exact_size(): void
    {
        $this->insertProduct(40, 'Blue28', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
            ['name' => 'Размер', 'value' => '28'],
        ]);
        $this->insertProduct(41, 'Blue32', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
            ['name' => 'Размер', 'value' => '32'],
        ]);
        $this->insertProduct(42, 'Black28', attributes: [
            ['name' => 'Цвет', 'value' => 'Чёрный'],
            ['name' => 'Размер', 'value' => '28'],
        ]);

        $results = $this->search->search(
            new SearchFilters(
                query: 'джинсы',
                attributes: [['name' => 'Цвет', 'value' => 'синий']],
                requiredAttributes: [['name' => 'Размер', 'value' => '28']],
            ),
            limit: 5,
            precomputedVector: $this->queryVector,
        );

        $ids = array_map(static fn (SearchHit $h): int => $h->product->product_id, $results);
        $this->assertSame([40, 42], $ids);
        $this->assertSame(40, $ids[0] ?? null);
    }

    public function test_hard_attributes_use_fallback_when_missing_from_vector_pool(): void
    {
        // Near vector for distractors, far vector for the only size-28 product.
        $near = array_fill(0, 768, 0.0);
        $near[0] = 1.0;
        $far = array_fill(0, 768, 0.0);
        $far[1] = 1.0;
        $query = array_fill(0, 768, 0.0);
        $query[0] = 1.0;

        for ($i = 1; $i <= 5; $i++) {
            $this->insertProduct(
                50 + $i,
                "Distractor{$i}",
                attributes: [
                    ['name' => 'Цвет', 'value' => 'Синий'],
                    ['name' => 'Размер', 'value' => '32'],
                ],
                embeddingVector: $near,
            );
        }

        $this->insertProduct(
            99,
            'RareSize28',
            attributes: [
                ['name' => 'Цвет', 'value' => 'Синий'],
                ['name' => 'Размер', 'value' => '28'],
            ],
            embeddingVector: $far,
        );

        $results = $this->search->search(
            new SearchFilters(
                query: 'джинсы',
                requiredAttributes: [
                    ['name' => 'Цвет', 'value' => 'синий'],
                    ['name' => 'Размер', 'value' => '28'],
                ],
            ),
            limit: 5,
            precomputedVector: $query,
        );

        $ids = array_map(static fn (SearchHit $h): int => $h->product->product_id, $results);
        $this->assertSame([99], $ids);
    }

    public function test_hard_attributes_return_empty_when_no_match_exists(): void
    {
        $this->insertProduct(60, 'Blue32', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
            ['name' => 'Размер', 'value' => '32'],
        ]);

        $results = $this->search->search(
            new SearchFilters(
                query: 'джинсы',
                requiredAttributes: [['name' => 'Размер', 'value' => '28']],
            ),
            limit: 5,
            precomputedVector: $this->queryVector,
        );

        $this->assertSame([], $results);
    }

    public function test_filters_by_store_id(): void
    {
        $this->insertProduct(20, 'InStore', storeId: 3, storeName: 'ТЦ Галерея');
        $this->insertProduct(21, 'Other', storeId: 1, storeName: 'Главный склад');

        $results = $this->search->search(
            new SearchFilters(storeId: 3, query: 'item'),
            limit: 10,
            precomputedVector: $this->queryVector,
        );

        $ids = array_map(static fn (SearchHit $h): int => $h->product->product_id, $results);
        $this->assertSame([20], $ids);
    }

    public function test_limit_is_clamped_between_1_and_20(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->insertProduct($i, "P{$i}");
        }

        $tooLow = $this->search->search(
            new SearchFilters(query: 'P'),
            limit: 0,
            precomputedVector: $this->queryVector,
        );
        $this->assertCount(1, $tooLow);

        $tooHigh = $this->search->search(
            new SearchFilters(query: 'P'),
            limit: 100,
            precomputedVector: $this->queryVector,
        );
        $this->assertCount(20, $tooHigh);
    }

    public function test_preserves_cosine_distance_order(): void
    {
        $near = array_fill(0, 768, 0.0);
        $near[0] = 1.0;
        $far = array_fill(0, 768, 0.0);
        $far[1] = 1.0;
        $query = array_fill(0, 768, 0.0);
        $query[0] = 1.0;

        $this->insertProduct(1, 'Near', embeddingVector: $near);
        $this->insertProduct(2, 'Far', embeddingVector: $far);

        $results = $this->search->search(
            new SearchFilters(query: 'near'),
            limit: 5,
            precomputedVector: $query,
        );

        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]->product->product_id);
        $this->assertSame(2, $results[1]->product->product_id);
        $this->assertLessThan($results[1]->distance, $results[0]->distance);
    }

    /**
     * @param  list<array{name: string, value: string}>  $attributes
     * @param  list<float>|null  $embeddingVector
     */
    private function insertProduct(
        int $productId,
        string $name,
        int $storeId = 1,
        string $storeName = 'Store',
        ?int $categoryId = 1,
        string $categoryName = 'Cat',
        float $price = 10,
        bool $inStock = true,
        bool $published = true,
        bool $embedding = true,
        array $attributes = [],
        ?array $embeddingVector = null,
    ): void {
        $product = ProductCatalog::query()->create([
            'product_id' => $productId,
            'store_id' => $storeId,
            'store_name' => $storeName,
            'name' => $name,
            'description' => $name,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'price' => $price,
            'attributes' => $attributes,
            'is_published' => $published,
            'in_stock' => $inStock,
        ]);

        if (! $embedding && $embeddingVector === null) {
            return;
        }

        $vector = $embeddingVector ?? array_fill(0, 768, 0.01);
        $literal = '['.implode(',', array_map(
            static fn (float $v): string => sprintf('%.8F', $v),
            $vector
        )).']';

        DB::statement(
            'UPDATE product_catalog SET embedding = ?::vector WHERE id = ?',
            [$literal, $product->id]
        );
    }
}
