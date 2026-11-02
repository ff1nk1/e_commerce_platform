<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\DTO\Catalog\ProductCard;

final class CandidatePromptFormatter
{
    /**
     * @param  list<ProductCard>  $products
     */
    public static function lines(array $products): string
    {
        if ($products === []) {
            return '(none)';
        }

        $lines = [];
        foreach ($products as $i => $product) {
            $n = $i + 1;
            $priceLabel = RubDisplay::format($product->price);
            $storeDisplayName = trim((string) $product->storeName);
            if ($storeDisplayName === '') {
                $storeDisplayName = 'id '.$product->storeId;
            }
            $attrs = self::formatAttributes($product->attributes);
            $line = sprintf(
                '%d. id:%s | %s | %s | магазин: %s',
                $n,
                (string) $product->productId,
                $product->name,
                $priceLabel,
                $storeDisplayName,
            );
            if ($attrs !== '') {
                $line .= ' | '.$attrs;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, mixed>  $attributes
     */
    private static function formatAttributes(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $parts[] = $name.': '.$value;
        }

        return implode('; ', $parts);
    }
}
