<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ProductSearchService;
use App\Services\SearchIndexService;

final class OpenSearchIndexAndSearchTest extends IntegrationTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function params(array $overrides = []): array
    {
        return array_merge([
            'q' => null,
            'category_ids' => [],
            'min_price' => null,
            'max_price' => null,
            'in_stock' => null,
            'attributes' => [],
            'page' => 1,
            'per_page' => 20,
            'sort' => 'new',
        ], $overrides);
    }

    public function test_index_product_then_search_by_name(): void
    {
        $index = resolve(SearchIndexService::class);
        $index->indexProduct($this->productDocument(101, [
            'name' => 'Alpha Wireless Headphones',
            'price' => 250.0,
        ]), preserveStock: false);

        $result = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'Alpha Wireless',
            'sort' => 'relevance',
        ]));

        $this->assertSame(1, $result['products']['total']);
        $this->assertSame(101, $result['products']['data'][0]['id']);
        $this->assertSame('Alpha Wireless Headphones', $result['products']['data'][0]['name']);
    }

    public function test_synonym_query_finds_product(): void
    {
        resolve(SearchIndexService::class)->indexProduct($this->productDocument(102, [
            'name' => 'Galaxy Smartphone',
            'description' => 'Android device',
        ]), preserveStock: false);

        $result = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'phone',
            'sort' => 'relevance',
        ]));

        $ids = array_column($result['products']['data'], 'id');
        $this->assertContains(102, $ids);
    }

    public function test_filters_category_price_stock_and_attributes(): void
    {
        $index = resolve(SearchIndexService::class);
        $index->indexProduct($this->productDocument(201, [
            'name' => 'Match All Filters',
            'category_id' => 10,
            'category_name' => 'Phones',
            'price' => 300.0,
            'in_stock' => true,
            'quantity' => 3,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ]), preserveStock: false);
        $index->indexProduct($this->productDocument(202, [
            'name' => 'Wrong Category',
            'category_id' => 99,
            'category_name' => 'Other',
            'price' => 300.0,
            'in_stock' => true,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ]), preserveStock: false);
        $index->indexProduct($this->productDocument(203, [
            'name' => 'Out Of Stock',
            'category_id' => 10,
            'category_name' => 'Phones',
            'price' => 300.0,
            'in_stock' => false,
            'quantity' => 0,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ]), preserveStock: false);
        $index->indexProduct($this->productDocument(204, [
            'name' => 'Wrong Attr',
            'category_id' => 10,
            'category_name' => 'Phones',
            'price' => 300.0,
            'in_stock' => true,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Red'],
            ],
        ]), preserveStock: false);
        $index->indexProduct($this->productDocument(205, [
            'name' => 'Too Expensive',
            'category_id' => 10,
            'category_name' => 'Phones',
            'price' => 9000.0,
            'in_stock' => true,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ]), preserveStock: false);

        $result = resolve(ProductSearchService::class)->search($this->params([
            'category_ids' => [10],
            'min_price' => 100.0,
            'max_price' => 500.0,
            'in_stock' => true,
            'attributes' => ['Color' => ['Black']],
            'sort' => 'new',
        ]));

        $ids = array_column($result['products']['data'], 'id');
        $this->assertSame([201], $ids);
    }

    public function test_post_filter_does_not_shrink_global_category_facets(): void
    {
        $index = resolve(SearchIndexService::class);
        $index->indexProduct($this->productDocument(301, [
            'name' => 'Cat One Item',
            'category_id' => 1,
            'category_name' => 'One',
            'price' => 50.0,
        ]), preserveStock: false);
        $index->indexProduct($this->productDocument(302, [
            'name' => 'Cat Two Item',
            'category_id' => 2,
            'category_name' => 'Two',
            'price' => 50.0,
        ]), preserveStock: false);

        $result = resolve(ProductSearchService::class)->search($this->params([
            'category_ids' => [1],
            'sort' => 'new',
        ]));

        $this->assertSame(1, $result['products']['total']);
        $facetIds = array_column($result['facets']['categories'], 'id');
        $this->assertContains(1, $facetIds);
        $this->assertContains(2, $facetIds);
    }

    public function test_sort_price_asc_and_desc(): void
    {
        $index = resolve(SearchIndexService::class);
        $index->indexProduct($this->productDocument(401, [
            'name' => 'Cheap',
            'price' => 10.0,
        ]), preserveStock: false);
        $index->indexProduct($this->productDocument(402, [
            'name' => 'Mid',
            'price' => 50.0,
        ]), preserveStock: false);
        $index->indexProduct($this->productDocument(403, [
            'name' => 'Expensive',
            'price' => 90.0,
        ]), preserveStock: false);

        $asc = resolve(ProductSearchService::class)->search($this->params(['sort' => 'price_asc']));
        $this->assertSame([401, 402, 403], array_column($asc['products']['data'], 'id'));

        $desc = resolve(ProductSearchService::class)->search($this->params(['sort' => 'price_desc']));
        $this->assertSame([403, 402, 401], array_column($desc['products']['data'], 'id'));
    }

    public function test_delete_product_removes_from_search(): void
    {
        $index = resolve(SearchIndexService::class);
        $index->indexProduct($this->productDocument(501, [
            'name' => 'Delete Me Unique',
        ]), preserveStock: false);

        $before = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'Delete Me Unique',
            'sort' => 'relevance',
        ]));
        $this->assertSame(1, $before['products']['total']);

        $index->deleteProduct(501);

        $after = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'Delete Me Unique',
            'sort' => 'relevance',
        ]));
        $this->assertSame(0, $after['products']['total']);
    }

    public function test_stock_update_affects_in_stock_filter(): void
    {
        $index = resolve(SearchIndexService::class);
        $index->indexProduct($this->productDocument(601, [
            'name' => 'Stock Filter Item',
            'quantity' => 5,
            'in_stock' => true,
        ]), preserveStock: false);

        $inStock = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'Stock Filter Item',
            'in_stock' => true,
            'sort' => 'relevance',
        ]));
        $this->assertSame(1, $inStock['products']['total']);

        $index->updateProductStock(601, 0);

        $stillInStock = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'Stock Filter Item',
            'in_stock' => true,
            'sort' => 'relevance',
        ]));
        $this->assertSame(0, $stillInStock['products']['total']);

        $outOfStock = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'Stock Filter Item',
            'in_stock' => false,
            'sort' => 'relevance',
        ]));
        $this->assertSame(1, $outOfStock['products']['total']);
    }

    public function test_category_rename_syncs_product_category_name(): void
    {
        $index = resolve(SearchIndexService::class);
        $index->indexProduct($this->productDocument(701, [
            'name' => 'Category Sync Product',
            'category_id' => 77,
            'category_name' => 'Old Category',
        ]), preserveStock: false);

        $index->syncCategoryNameOnProducts(77, 'Renamed Category');

        $result = resolve(ProductSearchService::class)->search($this->params([
            'q' => 'Renamed Category',
            'sort' => 'relevance',
        ]));

        $this->assertGreaterThanOrEqual(1, $result['products']['total']);
        $this->assertSame('Renamed Category', $result['products']['data'][0]['category_name']);
    }

    public function test_autocomplete_prefix_suggestions(): void
    {
        resolve(SearchIndexService::class)->indexProduct($this->productDocument(801, [
            'name' => 'Ultrabook Pro Max',
            'price' => 1200.0,
        ]), preserveStock: false);

        $empty = resolve(ProductSearchService::class)->autocomplete('U');
        $this->assertSame([], $empty['suggestions']);

        $result = resolve(ProductSearchService::class)->autocomplete('Ultra');
        $names = array_column($result['suggestions'], 'name');
        $this->assertContains('Ultrabook Pro Max', $names);
    }
}
