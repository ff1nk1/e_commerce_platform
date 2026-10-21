<?php

namespace Tests\Feature;

use App\Models\ProductCatalog;
use App\Services\Catalog\AttributeVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttributeVocabularyTest extends TestCase
{
    use RefreshDatabase;

    public function test_names_for_scope_returns_phone_attrs_only(): void
    {
        Cache::flush();
        $this->insert(1, 'Смартфоны', [['name' => 'Экран', 'value' => '6.1"'], ['name' => 'Память', 'value' => '128']]);
        $this->insert(2, 'Джинсы', [['name' => 'Размер', 'value' => '32']]);

        $names = app(AttributeVocabulary::class)->namesForScope([
            'key' => 'smartphones',
            'category_ilike' => 'Смартфон',
        ]);

        $this->assertContains('Экран', $names);
        $this->assertContains('Память', $names);
        $this->assertNotContains('Размер', $names);
    }

    public function test_null_scope_returns_empty(): void
    {
        $this->assertSame([], app(AttributeVocabulary::class)->namesForScope(null));
    }

    /**
     * @param  list<array{name: string, value: string}>  $attributes
     */
    private function insert(int $productId, string $category, array $attributes): void
    {
        $product = ProductCatalog::query()->create([
            'product_id' => $productId,
            'store_id' => 1,
            'store_name' => 'S',
            'name' => 'P'.$productId,
            'category_name' => $category,
            'price' => 10,
            'attributes' => $attributes,
            'is_published' => true,
            'in_stock' => true,
        ]);
        $vector = '['.implode(',', array_fill(0, 768, '0.01')).']';
        DB::statement('UPDATE product_catalog SET embedding = ?::vector WHERE id = ?', [$vector, $product->id]);
    }
}
