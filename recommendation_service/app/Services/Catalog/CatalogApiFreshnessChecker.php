<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\Catalog\Contracts\CatalogProductClient;
use App\Services\Catalog\Contracts\ProductFreshnessChecker;
use App\Services\Catalog\DTOs\SearchHit;
use Illuminate\Support\Facades\Log;

final class CatalogApiFreshnessChecker implements ProductFreshnessChecker
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
            // Full transport failure — keep local read-model hits (fail-open).
            Log::warning('ProductFreshnessChecker: catalog unavailable, keeping local hits', [
                'error' => $e->getMessage(),
            ]);

            return $hits;
        }

        $failedSet = array_fill_keys($remote->failed, true);
        $notFoundSet = array_fill_keys($remote->notFound, true);

        $kept = [];
        foreach ($hits as $hit) {
            $productId = (int) $hit->product->product_id;

            if (isset($notFoundSet[$productId])) {
                continue;
            }

            if (isset($failedSet[$productId])) {
                // Per-id transport/5xx/auth — keep local hit (do not treat as delete).
                $kept[] = $hit;

                continue;
            }

            if (! array_key_exists($productId, $remote->products)) {
                // Unexpected omission — treat as uncertain, keep local.
                $kept[] = $hit;

                continue;
            }

            $payload = $remote->products[$productId];
            if ($this->isUnavailable($payload)) {
                continue;
            }

            $kept[] = $hit;
        }

        return $kept;
    }

    /**
     * Catalog product show returns is_published but not stock.
     * Stock correctness comes from the Kafka read-model (in_stock), not this API.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isUnavailable(array $payload): bool
    {
        return array_key_exists('is_published', $payload) && ! (bool) $payload['is_published'];
    }
}
