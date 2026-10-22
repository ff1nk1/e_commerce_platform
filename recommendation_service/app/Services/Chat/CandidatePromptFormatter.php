<?php

namespace App\Services\Chat;

final class CandidatePromptFormatter
{
    /**
     * @param  list<array<string, mixed>>  $products
     */
    public static function lines(array $products): string
    {
        if ($products === []) {
            return '(none)';
        }

        $lines = [];
        foreach ($products as $i => $product) {
            $n = $i + 1;
            $priceLabel = RubDisplay::format(
                isset($product['price']) ? (is_scalar($product['price']) ? $product['price'] : null) : null
            );
            $store = trim((string) ($product['store_name'] ?? ''));
            if ($store === '') {
                $store = isset($product['store_id']) ? 'id '.$product['store_id'] : 'не указан';
            }
            $attrs = self::formatAttributes(
                is_array($product['attributes'] ?? null) ? $product['attributes'] : []
            );
            $line = sprintf(
                '%d. id:%s | %s | %s | магазин: %s',
                $n,
                (string) ($product['product_id'] ?? ''),
                $product['name'] ?? '',
                $priceLabel,
                $store,
            );
            if ($attrs !== '') {
                $line .= ' | '.$attrs;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{name?: string, value?: string}|mixed>  $attributes
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

        return $parts === [] ? '' : implode('; ', $parts);
    }
}
