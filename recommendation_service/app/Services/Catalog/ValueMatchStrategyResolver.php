<?php

namespace App\Services\Catalog;

/**
 * Maps attribute names to value-match strategy ids via config synonym groups.
 */
class ValueMatchStrategyResolver
{
    public const STRATEGY_NUMERIC = 'numeric';

    public const STRATEGY_TEXT = 'text';

    public const STRATEGY_SIZE_RANGE = 'size_range';

    public function strategyFor(string $attributeName): string
    {
        $concept = $this->conceptFor($attributeName);
        if ($concept === null) {
            return (string) config('attributes.value_match.default_strategy', self::STRATEGY_TEXT);
        }

        /** @var array<string, string> $byConcept */
        $byConcept = config('attributes.value_match.strategies_by_concept', []);

        return (string) ($byConcept[$concept] ?? config('attributes.value_match.default_strategy', self::STRATEGY_TEXT));
    }

    public function isSizeRange(string $attributeName): bool
    {
        return $this->strategyFor($attributeName) === self::STRATEGY_SIZE_RANGE;
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
        return \App\Support\RuText::fold($value);
    }
}
