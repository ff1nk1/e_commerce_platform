<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Products\ProductCache;
use PHPUnit\Framework\TestCase;

final class ProductCacheKeyTest extends TestCase
{
    public function test_page_is_part_of_the_cache_key(): void
    {
        $pageOne = ProductCache::categoryProducts('jeans', ['page' => 1, 'per_page' => 20]);
        $pageTwo = ProductCache::categoryProducts('jeans', ['page' => 2, 'per_page' => 20]);

        $this->assertNotSame($pageOne, $pageTwo);
    }

    public function test_key_is_stable_regardless_of_param_order(): void
    {
        $a = ProductCache::categoryProducts('jeans', ['page' => 2, 'sort' => 'new']);
        $b = ProductCache::categoryProducts('jeans', ['sort' => 'new', 'page' => 2]);

        $this->assertSame($a, $b);
    }
}
