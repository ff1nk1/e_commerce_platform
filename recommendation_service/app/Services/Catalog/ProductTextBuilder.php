<?php

namespace App\Services\Catalog;

use App\Models\ProductCatalog;

class ProductTextBuilder
{
    public function fromModel(ProductCatalog $product): string
    {
        return $this->fromParts(
            storeName: (string) ($product->store_name ?? ''),
            name: (string) ($product->name ?? ''),
            description: (string) ($product->description ?? ''),
            categoryName: (string) ($product->category_name ?? ''),
            attributes: $product->attributes ?? [],
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

        return implode("\n", array_filter(
            $lines,
            static fn (string $line): bool => trim($line) !== '' && ! str_ends_with(trim($line), ':')
        ));
    }
}
