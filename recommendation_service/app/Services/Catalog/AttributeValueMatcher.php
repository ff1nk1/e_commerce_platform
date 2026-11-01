<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\MatchStrategy;
use App\Services\Catalog\ValueMatchers\NumericValueMatcher;
use App\Services\Catalog\ValueMatchers\SizeRangeMatcher;
use App\Services\Catalog\ValueMatchers\TextValueMatcher;

/**
 * Facade: pick value-match strategy from config and compare product vs requested value.
 */
final class AttributeValueMatcher
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
            MatchStrategy::Numeric => $this->numeric->matches($productValue, $requestedValue),
            MatchStrategy::SizeRange => $this->sizeRange->matches($productValue, $requestedValue),
            MatchStrategy::Text => $this->text->matches($productValue, $requestedValue),
        };
    }

    public function strategyFor(string $attributeName): MatchStrategy
    {
        return $this->strategies->strategyFor($attributeName);
    }
}
