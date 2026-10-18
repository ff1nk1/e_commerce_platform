<?php

namespace Tests\Unit;

use App\Services\Catalog\SearchFiltersNormalizer;
use App\Services\Llm\DTOs\SearchFilters;
use PHPUnit\Framework\TestCase;

class SearchFiltersNormalizerTest extends TestCase
{
    public function test_defaults_in_stock_when_unset(): void
    {
        $normalizer = new SearchFiltersNormalizer(requireInStock: true);
        $normalized = $normalizer->normalize(new SearchFilters(query: 'джинсы'));

        $this->assertTrue($normalized->inStock);
        $this->assertSame('джинсы', $normalized->query);
    }

    public function test_keeps_explicit_out_of_stock(): void
    {
        $normalizer = new SearchFiltersNormalizer(requireInStock: true);
        $normalized = $normalizer->normalize(new SearchFilters(inStock: false, query: 'x'));

        $this->assertFalse($normalized->inStock);
    }

    public function test_trims_blank_names_to_null(): void
    {
        $normalizer = new SearchFiltersNormalizer(requireInStock: false);
        $normalized = $normalizer->normalize(new SearchFilters(
            storeName: '  ',
            categoryName: ' Джинсы ',
            query: '  ',
        ));

        $this->assertNull($normalized->storeName);
        $this->assertSame('Джинсы', $normalized->categoryName);
        $this->assertNull($normalized->query);
        $this->assertNull($normalized->inStock);
    }
}
