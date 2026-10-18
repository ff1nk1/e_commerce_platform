<?php

namespace Tests\Unit;

use App\Http\Requests\SearchProductsRequest;
use Tests\TestCase;

class SearchProductsRequestTest extends TestCase
{
    public function test_normalizes_scalar_and_array_category_ids(): void
    {
        $this->assertSame([3], $this->params(['category_id' => 3])['category_ids']);
        $this->assertSame([1, 2], $this->params(['category_id' => [1, 2, 1, '', null]])['category_ids']);
        $this->assertSame([], $this->params([])['category_ids']);
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
        ], $params['attributes']);
    }

    public function test_in_stock_boolean_normalization(): void
    {
        $this->assertTrue($this->params(['in_stock' => '1'])['in_stock']);
        $this->assertFalse($this->params(['in_stock' => '0'])['in_stock']);
        $this->assertTrue($this->params(['in_stock' => 'true'])['in_stock']);
        $this->assertFalse($this->params(['in_stock' => 'false'])['in_stock']);
        $this->assertNull($this->params([])['in_stock']);
        $this->assertNull($this->params(['in_stock' => ''])['in_stock']);
    }

    public function test_default_sort_depends_on_query(): void
    {
        $this->assertSame('relevance', $this->params(['q' => 'phone'])['sort']);
        $this->assertSame('new', $this->params([])['sort']);
        $this->assertSame('price_asc', $this->params(['q' => 'phone', 'sort' => 'price_asc'])['sort']);
    }

    public function test_trims_query_and_clamps_pagination(): void
    {
        $params = $this->params([
            'q' => '  laptop  ',
            'page' => 0,
            'per_page' => 999,
        ]);

        $this->assertSame('laptop', $params['q']);
        $this->assertSame(1, $params['page']);
        $this->assertSame(50, $params['per_page']);

        $this->assertNull($this->params(['q' => '   '])['q']);
        $this->assertSame(
            SearchProductsRequest::MAX_PAGE,
            $this->params(['page' => 99999])['page']
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

        $this->assertSame(10.5, $params['min_price']);
        $this->assertSame(99.0, $params['max_price']);
        $this->assertNull($this->params([])['min_price']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function params(array $query): array
    {
        $request = SearchProductsRequest::create('/api/search', 'GET', $query);
        $request->setContainer($this->app);

        return $request->searchParams();
    }
}
