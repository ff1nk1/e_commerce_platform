<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\DTO\Stock\StockLine;

final class StockItemNormalizer
{
    /**
     * Validate, merge duplicate (product_id, store_id) lines, and sort for lock order.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<StockLine>
     */
    public function normalize(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $parsed = [];

        foreach ($items as $item) {
            $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $storeId = filter_var($item['store_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);

            $hasValidProductId = $productId !== false && $productId >= 1;
            $hasValidStoreId = $storeId !== false && $storeId >= 1;
            $hasValidQuantity = $quantity !== false && $quantity >= 1;
            if (! $hasValidProductId || ! $hasValidStoreId || ! $hasValidQuantity) {
                return [];
            }

            $parsed[] = new StockLine($productId, $storeId, $quantity);
        }

        return $this->merge($parsed);
    }

    /**
     * Merge duplicate (product_id, store_id) lines and sort for lock order.
     *
     * @param  list<StockLine>  $items
     * @return list<StockLine>
     */
    public function merge(array $items): array
    {
        /** @var array<string, StockLine> $merged */
        $merged = [];

        foreach ($items as $item) {
            $key = $item->productId.':'.$item->storeId;
            if (isset($merged[$key])) {
                $merged[$key] = new StockLine(
                    $item->productId,
                    $item->storeId,
                    $merged[$key]->quantity + $item->quantity,
                );
            } else {
                $merged[$key] = $item;
            }
        }

        $normalized = array_values($merged);
        usort($normalized, static function (StockLine $a, StockLine $b): int {
            return [$a->productId, $a->storeId] <=> [$b->productId, $b->storeId];
        });

        return $normalized;
    }
}
