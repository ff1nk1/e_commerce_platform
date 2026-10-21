<?php

namespace App\Services\Catalog;

use App\Services\Catalog\ValueMatchers\NumericValueMatcher;
use App\Services\Catalog\ValueMatchers\SizeRangeMatcher;
use App\Services\Catalog\ValueMatchers\TextValueMatcher;

/**
 * Facade: pick value-match strategy from config and compare product vs requested value.
 */
class AttributeValueMatcher
{
    public function __construct(
        private readonly ValueMatchStrategyResolver $strategies = new ValueMatchStrategyResolver,
        private readonly NumericValueMatcher $numeric = new NumericValueMatcher,
        private readonly TextValueMatcher $text = new TextValueMatcher,
        private readonly SizeRangeMatcher $sizeRange = new SizeRangeMatcher,
    ) {}

    public function matches(string $attributeName, string $productValue, string $requestedValue): bool
    {
        return match ($this->strategies->strategyFor($attributeName)) {
            ValueMatchStrategyResolver::STRATEGY_NUMERIC => $this->numeric->matches($productValue, $requestedValue),
            ValueMatchStrategyResolver::STRATEGY_SIZE_RANGE => $this->sizeRange->matches($productValue, $requestedValue),
            default => $this->text->matches($productValue, $requestedValue),
        };
    }

    public function strategyFor(string $attributeName): string
    {
        return $this->strategies->strategyFor($attributeName);
    }
}
