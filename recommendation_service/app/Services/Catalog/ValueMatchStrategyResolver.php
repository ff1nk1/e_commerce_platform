<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\MatchStrategy;
use App\Support\RuText;

/**
 * Maps attribute names to value-match strategy ids via config synonym groups.
 */
final class ValueMatchStrategyResolver
{
    public function strategyFor(string $attributeName): MatchStrategy
    {
        $concept = $this->conceptFor($attributeName);
        $default = config('attributes.value_match.default_strategy', MatchStrategy::Text->value);
        if ($concept === null) {
            return $this->strategyFromConfig($default);
        }

        /** @var array<string, string> $byConcept */
        $byConcept = config('attributes.value_match.strategies_by_concept', []);

        return $this->strategyFromConfig($byConcept[$concept] ?? $default);
    }

    public function isSizeRange(string $attributeName): bool
    {
        return $this->strategyFor($attributeName) === MatchStrategy::SizeRange;
    }

    public function conceptFor(string $attributeName): ?string
    {
        $needle = $this->normalizeKey($attributeName);
        if ($needle === '') {
            return null;
        }

        /** @var array<string, list<string>> $groups */
        $groups = config('attributes.synonym_groups', []);

        foreach ($groups as $concept => $aliases) {
            foreach ($aliases as $alias) {
                $aliasKey = $this->normalizeKey((string) $alias);
                if ($aliasKey === '') {
                    continue;
                }
                if ($aliasKey === $needle || str_contains($needle, $aliasKey) || str_contains($aliasKey, $needle)) {
                    if (mb_strlen($aliasKey) < 3 && $aliasKey !== $needle) {
                        continue;
                    }

                    return (string) $concept;
                }
            }
        }

        return null;
    }

    private function normalizeKey(string $value): string
    {
        return RuText::fold($value);
    }

    private function strategyFromConfig(mixed $value): MatchStrategy
    {
        return MatchStrategy::tryFrom((string) $value) ?? MatchStrategy::Text;
    }
}
