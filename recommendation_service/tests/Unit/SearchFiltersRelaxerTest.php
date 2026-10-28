<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\SearchFiltersRelaxer;
use App\Services\Llm\DTOs\SearchFilters;
use PHPUnit\Framework\TestCase;

final class SearchFiltersRelaxerTest extends TestCase
{
    public function test_drops_material_before_color(): void
    {
        $relaxer = new SearchFiltersRelaxer;
        $filters = new SearchFilters(
            query: 'джинсы',
            requiredAttributes: [
                ['name' => 'Цвет', 'value' => 'синий'],
                ['name' => 'Материал', 'value' => 'шёлк'],
            ],
        );

        $step = $relaxer->relaxOnce($filters);
        $this->assertNotNull($step);
        $this->assertSame('Материал=шёлк', $step['relaxed']);
        $this->assertSame([
            ['name' => 'Цвет', 'value' => 'синий'],
        ], $step['filters']->requiredAttributes);
    }

    public function test_drops_store_name_then_price(): void
    {
        $relaxer = new SearchFiltersRelaxer;
        $filters = new SearchFilters(
            storeName: 'Галерея',
            priceMax: 100,
            query: 'x',
        );

        $step1 = $relaxer->relaxOnce($filters);
        $this->assertNotNull($step1);
        $this->assertSame('store_name=Галерея', $step1['relaxed']);

        $step2 = $relaxer->relaxOnce($step1['filters']);
        $this->assertNotNull($step2);
        $this->assertStringContainsString('price', $step2['relaxed']);
        $this->assertNull($step2['filters']->priceMax);
    }
}
