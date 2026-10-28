<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductCatalog;

final class ProductTextBuilder
{
    public function fromModel(ProductCatalog $product): string
    {
        $attributes = $product->attributes;
        if (! is_array($attributes)) {
            $attributes = [];
        }

        return $this->fromParts(
            name: (string) ($product->name ?? ''),
            description: (string) ($product->description ?? ''),
            categoryName: (string) ($product->category_name ?? ''),
            attributes: $attributes,
            storeName: (string) ($product->store_name ?? ''),
        );
    }

    /**
     * Semantic text for RETRIEVAL_DOCUMENT embeddings.
     * IDs and stock/publish flags are omitted to avoid query–document asymmetry.
     *
     * @param  list<array{name?: string, value?: string}>|array<string, mixed>  $attributes
     */
    public function fromParts(
        string $name,
        string $description,
        string $categoryName,
        array $attributes,
        string $storeName = '',
    ): string {
        $lines = [
            'store: '.$storeName,
            'name: '.$name,
            'description: '.$description,
            'category: '.$categoryName,
        ];

        $attributeLineParts = [];
        foreach ($attributes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $attrName = trim((string) ($row['name'] ?? ''));
            $attrValue = trim((string) ($row['value'] ?? ''));
            if ($attrName === '' || $attrValue === '') {
                continue;
            }
            $attributeLineParts[] = "{$attrName}: {$attrValue}";
        }

        if ($attributeLineParts !== []) {
            $lines[] = 'attributes: '.implode('; ', $attributeLineParts);
        }

        $nonEmptyLines = array_filter(
            $lines,
            static fn (string $line): bool => trim($line) !== '' && ! str_ends_with(trim($line), ':')
        );

        return implode("\n", $nonEmptyLines);
    }
}
