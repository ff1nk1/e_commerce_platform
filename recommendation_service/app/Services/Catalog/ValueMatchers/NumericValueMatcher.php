<?php

namespace App\Services\Catalog\ValueMatchers;

use App\Services\Catalog\AttributeValueNormalizer;

class NumericValueMatcher
{
    public function __construct(
        private readonly AttributeValueNormalizer $values = new AttributeValueNormalizer,
    ) {}

    public function matches(string $productValue, string $requestedValue): bool
    {
        return $this->values->numbersMatch($requestedValue, $productValue);
    }
}
