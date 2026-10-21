<?php

namespace App\Services\Catalog\ValueMatchers;

use App\Services\Catalog\AttributeValueNormalizer;

class TextValueMatcher
{
    public function __construct(
        private readonly AttributeValueNormalizer $values = new AttributeValueNormalizer,
    ) {}

    public function matches(string $productValue, string $requestedValue): bool
    {
        $requested = $this->values->normalizeForCompare($requestedValue);
        if ($requested === '') {
            return false;
        }

        $product = $this->values->normalizeForCompare($productValue);

        return str_contains($product, $requested)
            || str_contains($product, $this->values->normalizeForCompare($requestedValue, applyStem: false));
    }
}
