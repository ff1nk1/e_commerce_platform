<?php

declare(strict_types=1);

namespace App\Services\Catalog\ValueMatchers;

use App\Services\Catalog\AttributeValueNormalizer;

final class TextValueMatcher
{
    public function __construct(
        private readonly AttributeValueNormalizer $values = new AttributeValueNormalizer,
    ) {}

    public function matches(string $productValue, string $requestedValue): bool
    {
        $requestedStemmed = $this->values->normalizeForCompare($requestedValue);
        if ($requestedStemmed === '') {
            return false;
        }

        $productNormalized = $this->values->normalizeForCompare($productValue);
        $requestedUnstemmed = $this->values->normalizeForCompare($requestedValue, applyStem: false);

        return str_contains($productNormalized, $requestedStemmed)
            || str_contains($productNormalized, $requestedUnstemmed);
    }
}
