<?php

namespace App\Services\Catalog;

use App\Models\ProductCatalog;

class ProductTextBuilder
{
    public function fromModel(ProductCatalog $product): string
    {
        return $this->fromParts(
            productId: (int) $product->product_id,
            storeId: (int) $product->store_id,
            storeName: (string) ($product->store_name ?? ''),
            name: (string) ($product->name ?? ''),
            description: (string) ($product->description ?? ''),
            categoryName: (string) ($product->category_name ?? ''),
            attributes: is_array($product->attributes) ? $product->attributes : [],
            isPublished: (bool) $product->is_published,
            inStock: (bool) $product->in_stock,
        );
    }

    /**
     * @param  list<array{name?: string, value?: string}>|array<string, mixed>  $attributes
     */
    public function fromParts(
        int $productId,
        int $storeId,
        string $name,
        string $description,
        string $categoryName,
        array $attributes,
        bool $isPublished,
        bool $inStock,
        string $storeName = '',
    ): string {
        $lines = [
            "product_id: {$productId}",
            "store_id: {$storeId}",
            'store: '.$storeName,
            'name: '.$name,
            'description: '.$description,
            'category: '.$categoryName,
            'is_published: '.($isPublished ? 'true' : 'false'),
            'in_stock: '.($inStock ? 'true' : 'false'),
        ];

        $attrParts = [];
        foreach ($attributes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $attrName = trim((string) ($row['name'] ?? ''));
            $attrValue = trim((string) ($row['value'] ?? ''));
            if ($attrName === '' || $attrValue === '') {
                continue;
            }
            $attrParts[] = "{$attrName}: {$attrValue}";
        }

        if ($attrParts !== []) {
            $lines[] = 'attributes: '.implode('; ', $attrParts);
        }

        return implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== '' && ! str_ends_with(trim($line), ':')));
    }
}
