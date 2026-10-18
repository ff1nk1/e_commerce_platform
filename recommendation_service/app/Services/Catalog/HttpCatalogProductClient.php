<?php

namespace App\Services\Catalog;

use App\Services\Catalog\Contracts\CatalogProductClient;
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

    public function fetchByIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $productIds,
            static fn (mixed $id): bool => is_int($id) ? $id > 0 : (is_numeric($id) && (int) $id > 0)
        )));
        $ids = array_map(static fn (mixed $id): int => (int) $id, $ids);

        if ($ids === []) {
            return [];
        }

        $base = rtrim($this->baseUrl, '/');
        $timeout = $this->timeout;

        $responses = Http::pool(fn (Pool $pool) => array_map(
            static fn (int $id) => $pool->as((string) $id)
                ->acceptJson()
                ->timeout($timeout)
                ->get($base.'/api/catalog/products/'.$id),
            $ids
        ));

        $connectionFailures = 0;

        $result = [];
        foreach ($ids as $id) {
            $response = $responses[(string) $id] ?? null;
            if (! $response instanceof Response) {
                $connectionFailures++;

                continue;
            }
            if ($response->status() === 404) {
                continue;
            }
            if (! $response->successful()) {
                // Treat 5xx / connection-like failures as transport issues for fail-open.
                if ($response->serverError() || $response->status() === 0) {
                    $connectionFailures++;
                }
                Log::debug('CatalogProductClient non-success', [
                    'product_id' => $id,
                    'status' => $response->status(),
                ]);

                continue;
            }

            $product = $response->json('product');
            if (! is_array($product)) {
                continue;
            }

            $result[$id] = $product;
        }

        if ($result === [] && $connectionFailures === count($ids)) {
            throw new \RuntimeException('Catalog service unreachable for all product freshness checks');
        }

        return $result;
    }
}
