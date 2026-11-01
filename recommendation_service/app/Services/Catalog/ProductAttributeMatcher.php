<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductCatalog;

final class ProductAttributeMatcher
{
    public function __construct(
        private readonly AttributeNameNormalizer $attributeNames = new AttributeNameNormalizer,
        private readonly AttributeValueMatcher $attributeValues = new AttributeValueMatcher,
    ) {}

    public function satisfies(
        ProductCatalog $product,
        string $name,
        string $value,
        bool $valueOnly = false,
    ): bool {
        if (trim($value) === '') {
            return false;
        }

        $rows = $product->attributes ?? [];
        if (! is_array($rows)) {
            return false;
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $attrName = (string) ($row['name'] ?? '');
            $attrValue = (string) ($row['value'] ?? '');
            if ($attrName === '' || $attrValue === '') {
                continue;
            }
            if (! $valueOnly) {
                if ($name === '' || ! $this->attributeNames->namesMatch($attrName, $name)) {
                    continue;
                }
            }
            // value_only: match against the product attr name's strategy (or default text).
            $strategyName = $valueOnly ? $attrName : $name;
            if ($this->attributeValues->matches($strategyName, $attrValue, $value)) {
                return true;
            }
        }

        return false;
    }
}
