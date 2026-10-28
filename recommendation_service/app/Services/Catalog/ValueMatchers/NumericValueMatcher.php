<?php

declare(strict_types=1);

namespace App\Services\Catalog\ValueMatchers;

use App\Services\Catalog\AttributeValueNormalizer;

final class NumericValueMatcher
{
    public function __construct(
        private readonly AttributeValueNormalizer $values = new AttributeValueNormalizer,
    ) {}

    public function matches(string $productValue, string $requestedValue): bool
    {
        return $this->values->numbersMatch($requestedValue, $productValue);
    }
}
