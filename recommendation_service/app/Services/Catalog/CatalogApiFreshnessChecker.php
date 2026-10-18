<?php

namespace App\Services\Catalog;

use App\Services\Catalog\Contracts\CatalogProductClient;
use App\Services\Catalog\Contracts\ProductFreshnessChecker;
use App\Services\Catalog\DTOs\SearchHit;
use Illuminate\Support\Facades\Log;

class CatalogApiFreshnessChecker implements ProductFreshnessChecker
{
    public function __construct(
        private readonly CatalogProductClient $catalog,
    ) {}

    public function keepFresh(array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        $ids = array_map(
            static fn (SearchHit $hit): int => (int) $hit->product->product_id,
            $hits
        );

        try {
            $remote = $this->catalog->fetchByIds($ids);
        } catch (\Throwable $e) {
            // Transport failure — keep local read-model hits (fail-open).
            Log::warning('ProductFreshnessChecker: catalog unavailable, keeping local hits', [
                'error' => $e->getMessage(),
            ]);

            return $hits;
        }

        $kept = [];
        foreach ($hits as $hit) {
            $productId = (int) $hit->product->product_id;
            if (! array_key_exists($productId, $remote)) {
                // Explicit miss (404) — drop.
                continue;
            }

            $payload = $remote[$productId];
            if ($this->isUnavailable($payload)) {
                continue;
            }

            $kept[] = $hit;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isUnavailable(array $payload): bool
    {
        if (array_key_exists('is_published', $payload) && ! (bool) $payload['is_published']) {
            return true;
        }

        if (array_key_exists('in_stock', $payload) && ! (bool) $payload['in_stock']) {
            return true;
        }

        if (array_key_exists('quantity', $payload) && is_numeric($payload['quantity'])) {
            return ((int) $payload['quantity']) <= 0;
        }

        return false;
    }
}
