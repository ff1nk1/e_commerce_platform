<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductCatalog;
use App\Services\Catalog\CatalogApiFreshnessChecker;
use App\Services\Catalog\Contracts\CatalogProductClient;
use App\Services\Catalog\DTOs\CatalogFetchResult;
use App\Services\Catalog\DTOs\SearchHit;
use RuntimeException;
use Tests\TestCase;

final class CatalogApiFreshnessCheckerTest extends TestCase
{
    public function test_drops_not_found_and_unpublished(): void
    {
        $client = new class implements CatalogProductClient
        {
            public function fetchByIds(array $productIds): CatalogFetchResult
            {
                return new CatalogFetchResult(
                    products: [
                        1 => ['id' => 1, 'is_published' => true],
                        2 => ['id' => 2, 'is_published' => false],
                    ],
                    notFound: [3],
                );
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

    public function test_keeps_hits_when_per_id_fetch_fails(): void
    {
        $client = new class implements CatalogProductClient
        {
            public function fetchByIds(array $productIds): CatalogFetchResult
            {
                return new CatalogFetchResult(
                    products: [
                        1 => ['id' => 1, 'is_published' => true],
                    ],
                    failed: [2],
                );
            }
        };

        $checker = new CatalogApiFreshnessChecker($client);
        $kept = $checker->keepFresh([
            new SearchHit(new ProductCatalog(['product_id' => 1]), 0.1),
            new SearchHit(new ProductCatalog(['product_id' => 2]), 0.1),
        ]);

        $this->assertCount(2, $kept);
        $this->assertSame([1, 2], array_map(
            static fn (SearchHit $hit): int => (int) $hit->product->product_id,
            $kept,
        ));
    }

    public function test_ignores_remote_stock_fields(): void
    {
        $client = new class implements CatalogProductClient
        {
            public function fetchByIds(array $productIds): CatalogFetchResult
            {
                return new CatalogFetchResult(
                    products: [
                        1 => ['id' => 1, 'is_published' => true, 'in_stock' => false, 'quantity' => 0],
                    ],
                );
            }
        };

        $checker = new CatalogApiFreshnessChecker($client);
        $kept = $checker->keepFresh([
            new SearchHit(new ProductCatalog(['product_id' => 1]), 0.1),
        ]);

        $this->assertCount(1, $kept);
    }

    public function test_fail_open_on_catalog_transport_error(): void
    {
        $client = new class implements CatalogProductClient
        {
            public function fetchByIds(array $productIds): CatalogFetchResult
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
