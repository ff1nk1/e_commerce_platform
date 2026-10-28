<?php

declare(strict_types=1);

namespace App\Services\Stock;

final class StockItemNormalizer
{
    /**
     * Validate, merge duplicate (product_id, store_id) lines, and sort for lock order.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{product_id: int, store_id: int, quantity: int}>|null
     */
    public function normalize(array $items): ?array
    {
        if ($items === []) {
            return null;
        }

        /** @var array<string, array{product_id: int, store_id: int, quantity: int}> $merged */
        $merged = [];

        foreach ($items as $item) {
            $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $storeId = filter_var($item['store_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);

            $hasValidProductId = $productId !== false && $productId >= 1;
            $hasValidStoreId = $storeId !== false && $storeId >= 1;
            $hasValidQuantity = $quantity !== false && $quantity >= 1;
            if (! $hasValidProductId || ! $hasValidStoreId || ! $hasValidQuantity) {
                return null;
            }

            $key = $productId.':'.$storeId;
            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += $quantity;
            } else {
                $merged[$key] = [
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'quantity' => $quantity,
                ];
            }
        }

        $normalized = array_values($merged);
        usort($normalized, static function (array $a, array $b): int {
            return [$a['product_id'], $a['store_id']] <=> [$b['product_id'], $b['store_id']];
        });

        return $normalized;
    }
}
