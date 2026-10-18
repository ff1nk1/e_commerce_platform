<?php

namespace Tests\Unit;

use App\Models\ProductCatalog;
use App\Services\Catalog\CatalogApiFreshnessChecker;
use App\Services\Catalog\Contracts\CatalogProductClient;
use App\Services\Catalog\DTOs\SearchHit;
use RuntimeException;
use Tests\TestCase;

class CatalogApiFreshnessCheckerTest extends TestCase
{
    public function test_drops_missing_and_unpublished(): void
    {
        $client = new class implements CatalogProductClient
        {
            public function fetchByIds(array $productIds): array
            {
                return [
                    1 => ['id' => 1, 'is_published' => true, 'in_stock' => true],
                    2 => ['id' => 2, 'is_published' => false, 'in_stock' => true],
                ];
            }
        };

        $checker = new CatalogApiFreshnessChecker($client);
        $kept = $checker->keepFresh([
            new SearchHit(new ProductCatalog(['product_id' => 1]), 0.1),
            new SearchHit(new ProductCatalog(['product_id' => 2]), 0.1),
            new SearchHit(new ProductCatalog(['product_id' => 3]), 0.1),
        ]);

        $this->assertCount(1, $kept);
        $this->assertSame(1, $kept[0]->product->product_id);
    }

    public function test_fail_open_on_catalog_transport_error(): void
    {
        $client = new class implements CatalogProductClient
        {
            public function fetchByIds(array $productIds): array
            {
                throw new RuntimeException('down');
            }
        };

        $hits = [
            new SearchHit(new ProductCatalog(['product_id' => 9]), 0.1),
        ];
        $checker = new CatalogApiFreshnessChecker($client);

        $this->assertSame($hits, $checker->keepFresh($hits));
    }
}
