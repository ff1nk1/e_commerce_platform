<?php

namespace App\Services\Catalog\ValueMatchers;

use App\Services\Catalog\AttributeValueNormalizer;
use App\Support\RuText;

class SizeRangeMatcher
{
    public function __construct(
        private readonly AttributeValueNormalizer $values = new AttributeValueNormalizer,
        private readonly TextValueMatcher $textFallback = new TextValueMatcher,
    ) {}

    public function matches(string $productValue, string $requestedValue): bool
    {
        $constraint = $this->parseConstraint($requestedValue);
        $productSize = $this->values->extractNumber($productValue);

        if ($constraint !== null && $productSize !== null) {
            if ($productSize < $constraint['min']) {
                return false;
            }
            if ($constraint['max'] !== null && $productSize > $constraint['max']) {
                return false;
            }

            return true;
        }

        return $this->textFallback->matches($productValue, $requestedValue);
    }

    /**
     * @return array{min: float, max: float|null}|null
     */
    private function parseConstraint(string $value): ?array
    {
        $normalized = RuText::fold($value);

        /** @var array{from?: string, exact?: string} $patterns */
        $patterns = config('attributes.value_match.size_range_patterns', []);

        $fromPattern = $patterns['from'] ?? null;
        if (is_string($fromPattern) && $fromPattern !== ''
            && preg_match($fromPattern, $normalized, $matches) === 1) {
            return [
                'min' => (float) str_replace(',', '.', $matches[1]),
                'max' => null,
            ];
        }

        $exactPattern = $patterns['exact'] ?? null;
        if (is_string($exactPattern) && $exactPattern !== ''
            && preg_match($exactPattern, trim($normalized), $matches) === 1) {
            $n = (float) str_replace(',', '.', $matches[1]);

            return ['min' => $n, 'max' => $n];
        }

        return null;
    }
}
