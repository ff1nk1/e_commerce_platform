<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Search\ProductSearchQuery;
use App\Enums\ProductSort;
use App\Http\Requests\SearchProductsRequest;
use Tests\TestCase;

final class SearchProductsRequestTest extends TestCase
{
    public function test_normalizes_scalar_and_array_category_ids(): void
    {
        $this->assertSame([3], $this->params(['category_id' => 3])->categoryIds);
        $this->assertSame([1, 2], $this->params(['category_id' => [1, 2, 1, '', null]])->categoryIds);
        $this->assertSame([], $this->params([])->categoryIds);
    }

    public function test_normalizes_attributes_and_skips_empty_values(): void
    {
        $params = $this->params([
            'attr' => [
                'Color' => ['Black', 'Black', ''],
                'Size' => 'M',
                ' ' => ['ignored'],
                'Material' => ['', '  '],
            ],
        ]);

        $this->assertSame([
            'Color' => ['Black'],
            'Size' => ['M'],
        ], $params->attributes);
    }

    public function test_in_stock_boolean_normalization(): void
    {
        $this->assertTrue($this->params(['in_stock' => '1'])->inStock);
        $this->assertFalse($this->params(['in_stock' => '0'])->inStock);
        $this->assertTrue($this->params(['in_stock' => 'true'])->inStock);
        $this->assertFalse($this->params(['in_stock' => 'false'])->inStock);
        $this->assertNull($this->params([])->inStock);
        $this->assertNull($this->params(['in_stock' => ''])->inStock);
    }

    public function test_default_sort_depends_on_query(): void
    {
        $this->assertSame(ProductSort::Relevance, $this->params(['q' => 'phone'])->sort);
        $this->assertSame(ProductSort::New, $this->params([])->sort);
        $this->assertSame(ProductSort::PriceAsc, $this->params(['q' => 'phone', 'sort' => 'price_asc'])->sort);
    }

    public function test_trims_query_and_clamps_pagination(): void
    {
        $params = $this->params([
            'q' => '  laptop  ',
            'page' => 0,
            'per_page' => 999,
        ]);

        $this->assertSame('laptop', $params->q);
        $this->assertSame(1, $params->page);
        $this->assertSame(50, $params->perPage);

        $this->assertNull($this->params(['q' => '   '])->q);
        $this->assertSame(
            SearchProductsRequest::MAX_PAGE,
            $this->params(['page' => 99999])->page
        );
    }

    public function test_skip_analytics_requires_config_flag(): void
    {
        $request = SearchProductsRequest::create('/api/search', 'GET', []);
        $request->headers->set('X-Skip-Analytics', '1');
        $request->setContainer($this->app);

        config(['opensearch.allow_skip_analytics' => false]);
        $this->assertFalse($request->shouldSkipAnalytics());

        config(['opensearch.allow_skip_analytics' => true]);
        $this->assertTrue($request->shouldSkipAnalytics());
    }

    public function test_price_fields_cast_to_float_when_filled(): void
    {
        $params = $this->params([
            'min_price' => '10.5',
            'max_price' => '99',
        ]);

        $this->assertSame(10.5, $params->minPrice);
        $this->assertSame(99.0, $params->maxPrice);
        $this->assertNull($this->params([])->minPrice);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function params(array $query): ProductSearchQuery
    {
        $request = SearchProductsRequest::create('/api/search', 'GET', $query);
        $request->setContainer($this->app);

        return $request->searchParams();
    }
}
