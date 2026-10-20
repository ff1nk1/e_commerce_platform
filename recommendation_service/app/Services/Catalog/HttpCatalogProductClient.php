<?php

namespace App\Services\Catalog;

use App\Services\Catalog\Contracts\CatalogProductClient;
use App\Services\Catalog\DTOs\CatalogFetchResult;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpCatalogProductClient implements CatalogProductClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 5,
    ) {}

    public function fetchByIds(array $productIds): CatalogFetchResult
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $productIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return new CatalogFetchResult;
        }

        $base = rtrim($this->baseUrl, '/');
        $timeout = $this->timeout;

        $responses = Http::pool(fn (Pool $pool) => array_map(
            static fn (int $id) => $pool->as((string) $id)
                ->acceptJson()
                ->withOptions(['allow_redirects' => false])
                ->timeout($timeout)
                ->get($base.'/api/catalog/products/'.$id),
            $ids
        ));

        $products = [];
        $notFound = [];
        $failed = [];

        foreach ($ids as $id) {
            $response = $responses[(string) $id] ?? null;
            if (! $response instanceof Response) {
                $failed[] = $id;

                continue;
            }

            if ($response->status() === 404) {
                $notFound[] = $id;

                continue;
            }

            if (! $response->successful()) {
                $failed[] = $id;
                Log::debug('CatalogProductClient non-success', [
                    'product_id' => $id,
                    'status' => $response->status(),
                ]);

                continue;
            }

            $product = $response->json('product');
            if (! is_array($product)) {
                $failed[] = $id;

                continue;
            }

            $products[$id] = $product;
        }

        if ($products === [] && $notFound === [] && $failed !== [] && count($failed) === count($ids)) {
            throw new \RuntimeException('Catalog service unreachable for all product freshness checks');
        }

        return new CatalogFetchResult(
            products: $products,
            notFound: $notFound,
            failed: $failed,
        );
    }
}
