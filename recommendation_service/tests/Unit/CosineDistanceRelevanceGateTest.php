<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductCatalog;
use App\Services\Catalog\CosineDistanceRelevanceGate;
use App\Services\Catalog\DTOs\SearchHit;
use PHPUnit\Framework\TestCase;

final class CosineDistanceRelevanceGateTest extends TestCase
{
    public function test_filters_hits_above_threshold(): void
    {
        // Boundary check for an explicit threshold (not the config default).
        $gate = new CosineDistanceRelevanceGate(maxCosineDistance: 0.55);
        $product = new ProductCatalog(['product_id' => 1]);

        $accepted = $gate->accept([
            new SearchHit($product, 0.2),
            new SearchHit($product, 0.55),
            new SearchHit($product, 0.56),
            new SearchHit($product, 1.2),
        ]);

        $this->assertCount(2, $accepted);
        $this->assertSame(0.2, $accepted[0]->distance);
        $this->assertSame(0.55, $accepted[1]->distance);
    }

    public function test_default_config_threshold_keeps_typical_retrieval_distances(): void
    {
        $gate = new CosineDistanceRelevanceGate(maxCosineDistance: 0.75);
        $product = new ProductCatalog(['product_id' => 1]);

        $accepted = $gate->accept([
            new SearchHit($product, 0.6),
            new SearchHit($product, 0.75),
            new SearchHit($product, 0.76),
        ]);

        $this->assertCount(2, $accepted);
        $this->assertSame(0.6, $accepted[0]->distance);
        $this->assertSame(0.75, $accepted[1]->distance);
    }

    public function test_empty_input_stays_empty(): void
    {
        $gate = new CosineDistanceRelevanceGate(maxCosineDistance: 0.75);
        $this->assertSame([], $gate->accept([]));
    }
}
