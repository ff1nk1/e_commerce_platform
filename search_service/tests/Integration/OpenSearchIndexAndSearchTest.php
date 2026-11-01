<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\DTO\Search\AutocompleteQuery;
use App\DTO\Search\ProductDocument;
use App\DTO\Search\ProductSearchQuery;
use App\Services\Indexing\CategoryIndexer;
use App\Services\Indexing\ProductIndexer;
use App\Services\Search\AutocompleteProductsService;
use App\Services\Search\SearchProductsService;

final class OpenSearchIndexAndSearchTest extends IntegrationTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function params(array $overrides = []): ProductSearchQuery
    {
        /** @var array{q?: string|null, category_ids?: list<int>, min_price?: float|null, max_price?: float|null, in_stock?: bool|null, attributes?: array<string, list<string>>, page?: int, per_page?: int, sort?: string} $params */
        $params = array_merge([
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

        return ProductSearchQuery::fromArray($params);
    }

    public function test_index_product_then_search_by_name(): void
    {
        $index = resolve(ProductIndexer::class);
        $index->upsert(ProductDocument::fromArray($this->productDocument(101, [
            'name' => 'Alpha Wireless Headphones',
            'price' => 250.0,
        ])), preserveStock: false);

        $result = resolve(SearchProductsService::class)->handle($this->params([
            'q' => 'Alpha Wireless',
            'sort' => 'relevance',
        ]));

        $this->assertSame(1, $result->products['total']);
        $this->assertSame(101, $result->products['data'][0]['id']);
        $this->assertSame('Alpha Wireless Headphones', $result->products['data'][0]['name']);
    }

    public function test_synonym_query_finds_product(): void
    {
        resolve(ProductIndexer::class)->upsert(ProductDocument::fromArray($this->productDocument(102, [
            'name' => 'Galaxy Smartphone',
            'description' => 'Android device',
        ])), preserveStock: false);

        $result = resolve(SearchProductsService::class)->handle($this->params([
            'q' => 'phone',
            'sort' => 'relevance',
        ]));

        $ids = array_column($result->products['data'], 'id');
        $this->assertContains(102, $ids);
    }

    public function test_filters_category_price_stock_and_attributes(): void
    {
        $index = resolve(ProductIndexer::class);
        $index->upsert(ProductDocument::fromArray($this->productDocument(201, [
            'name' => 'Match All Filters',
            'category_id' => 10,
            'category_name' => 'Phones',
            'price' => 300.0,
            'in_stock' => true,
            'quantity' => 3,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ])), preserveStock: false);
        $index->upsert(ProductDocument::fromArray($this->productDocument(202, [
            'name' => 'Wrong Category',
            'category_id' => 99,
            'category_name' => 'Other',
            'price' => 300.0,
            'in_stock' => true,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ])), preserveStock: false);
        $index->upsert(ProductDocument::fromArray($this->productDocument(203, [
            'name' => 'Out Of Stock',
            'category_id' => 10,
            'category_name' => 'Phones',
            'price' => 300.0,
            'in_stock' => false,
            'quantity' => 0,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ])), preserveStock: false);
        $index->upsert(ProductDocument::fromArray($this->productDocument(204, [
            'name' => 'Wrong Attr',
            'category_id' => 10,
            'category_name' => 'Phones',
            'price' => 300.0,
            'in_stock' => true,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Red'],
            ],
        ])), preserveStock: false);
        $index->upsert(ProductDocument::fromArray($this->productDocument(205, [
            'name' => 'Too Expensive',
            'category_id' => 10,
            'category_name' => 'Phones',
            'price' => 9000.0,
            'in_stock' => true,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ])), preserveStock: false);

        $result = resolve(SearchProductsService::class)->handle($this->params([
            'category_ids' => [10],
            'min_price' => 100.0,
            'max_price' => 500.0,
            'in_stock' => true,
            'attributes' => ['Color' => ['Black']],
            'sort' => 'new',
        ]));

        $ids = array_column($result->products['data'], 'id');
        $this->assertSame([201], $ids);
    }

    public function test_post_filter_does_not_shrink_global_category_facets(): void
    {
        $index = resolve(ProductIndexer::class);
        $index->upsert(ProductDocument::fromArray($this->productDocument(301, [
            'name' => 'Cat One Item',
            'category_id' => 1,
            'category_name' => 'One',
            'price' => 50.0,
        ])), preserveStock: false);
        $index->upsert(ProductDocument::fromArray($this->productDocument(302, [
            'name' => 'Cat Two Item',
            'category_id' => 2,
            'category_name' => 'Two',
            'price' => 50.0,
        ])), preserveStock: false);

        $result = resolve(SearchProductsService::class)->handle($this->params([
            'category_ids' => [1],
            'sort' => 'new',
        ]));

        $this->assertSame(1, $result->products['total']);
        $facetIds = array_column($result->facets['categories'], 'id');
        $this->assertContains(1, $facetIds);
        $this->assertContains(2, $facetIds);
    }

    public function test_sort_price_asc_and_desc(): void
    {
        $index = resolve(ProductIndexer::class);
        $index->upsert(ProductDocument::fromArray($this->productDocument(401, [
            'name' => 'Cheap',
            'price' => 10.0,
        ])), preserveStock: false);
        $index->upsert(ProductDocument::fromArray($this->productDocument(402, [
            'name' => 'Mid',
            'price' => 50.0,
        ])), preserveStock: false);
        $index->upsert(ProductDocument::fromArray($this->productDocument(403, [
            'name' => 'Expensive',
            'price' => 90.0,
        ])), preserveStock: false);

        $asc = resolve(SearchProductsService::class)->handle($this->params(['sort' => 'price_asc']));
        $this->assertSame([401, 402, 403], array_column($asc->products['data'], 'id'));

        $desc = resolve(SearchProductsService::class)->handle($this->params(['sort' => 'price_desc']));
        $this->assertSame([403, 402, 401], array_column($desc->products['data'], 'id'));
    }

    public function test_delete_product_removes_from_search(): void
    {
        $index = resolve(ProductIndexer::class);
        $index->upsert(ProductDocument::fromArray($this->productDocument(501, [
            'name' => 'Delete Me Unique',
        ])), preserveStock: false);

        $before = resolve(SearchProductsService::class)->handle($this->params([
            'q' => 'Delete Me Unique',
            'sort' => 'relevance',
        ]));
        $this->assertSame(1, $before->products['total']);

        $index->delete(501);

        $after = resolve(SearchProductsService::class)->handle($this->params([
            'q' => 'Delete Me Unique',
            'sort' => 'relevance',
        ]));
        $this->assertSame(0, $after->products['total']);
    }

    public function test_stock_update_affects_in_stock_filter(): void
    {
        $index = resolve(ProductIndexer::class);
        $index->upsert(ProductDocument::fromArray($this->productDocument(601, [
            'name' => 'Stock Filter Item',
            'quantity' => 5,
            'in_stock' => true,
        ])), preserveStock: false);

        $inStock = resolve(SearchProductsService::class)->handle($this->params([
            'q' => 'Stock Filter Item',
            'in_stock' => true,
            'sort' => 'relevance',
        ]));
        $this->assertSame(1, $inStock->products['total']);

        $index->updateStock(601, 0);

        $stillInStock = resolve(SearchProductsService::class)->handle($this->params([
            'q' => 'Stock Filter Item',
            'in_stock' => true,
            'sort' => 'relevance',
        ]));
        $this->assertSame(0, $stillInStock->products['total']);

        $outOfStock = resolve(SearchProductsService::class)->handle($this->params([
            'q' => 'Stock Filter Item',
            'in_stock' => false,
            'sort' => 'relevance',
        ]));
        $this->assertSame(1, $outOfStock->products['total']);
    }

    public function test_category_rename_syncs_product_category_name(): void
    {
        $index = resolve(ProductIndexer::class);
        $index->upsert(ProductDocument::fromArray($this->productDocument(701, [
            'name' => 'Category Sync Product',
            'category_id' => 77,
            'category_name' => 'Old Category',
        ])), preserveStock: false);

        resolve(CategoryIndexer::class)->syncNameOnProducts(77, 'Renamed Category');

        $result = resolve(SearchProductsService::class)->handle($this->params([
            'q' => 'Renamed Category',
            'sort' => 'relevance',
        ]));

        $this->assertGreaterThanOrEqual(1, $result->products['total']);
        $this->assertSame('Renamed Category', $result->products['data'][0]['category_name']);
    }

    public function test_autocomplete_prefix_suggestions(): void
    {
        resolve(ProductIndexer::class)->upsert(ProductDocument::fromArray($this->productDocument(801, [
            'name' => 'Ultrabook Pro Max',
            'price' => 1200.0,
        ])), preserveStock: false);

        $empty = resolve(AutocompleteProductsService::class)->handle(new AutocompleteQuery(q: 'U'));
        $this->assertSame([], $empty->suggestions);

        $result = resolve(AutocompleteProductsService::class)->handle(new AutocompleteQuery(q: 'Ultra'));
        $names = array_column($result->suggestions, 'name');
        $this->assertContains('Ultrabook Pro Max', $names);
    }
}
