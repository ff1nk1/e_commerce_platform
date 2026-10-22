<?php

namespace App\Services\Catalog;

/**
 * Resolve extracted attribute names against a scoped catalog vocabulary.
 */
class AttributeResolver
{
    private const MIN_FUZZY_MATCH_PERCENT = 80;

    public function __construct(
        private readonly AttributeNameNormalizer $names = new AttributeNameNormalizer,
    ) {}

    /**
     * @param  list<array{name: string, value: string}>  $attributes
     * @param  list<string>  $vocabulary
     * @return list<array{name: string, value: string, value_only?: bool}>
     */
    public function resolveList(array $attributes, array $vocabulary): array
    {
        $out = [];
        foreach ($attributes as $row) {
            $resolved = $this->resolveOne(
                (string) $row['name'],
                (string) $row['value'],
                $vocabulary,
            );
            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $vocabulary
     * @return array{name: string, value: string, value_only?: bool}|null
     */
    public function resolveOne(string $name, string $value, array $vocabulary): ?array
    {
        $name = trim($name);
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($name === '') {
            return ['name' => '', 'value' => $value, 'value_only' => true];
        }

        $canonical = $this->names->canonicalize($name);
        if ($vocabulary === []) {
            // No product-type scope: keep aliased name, skip value_only demotion.
            return ['name' => $canonical !== '' ? $canonical : $name, 'value' => $value];
        }

        $vocabExact = $this->findExactInVocabulary($canonical, $vocabulary);
        if ($vocabExact !== null) {
            return ['name' => $vocabExact, 'value' => $value];
        }

        $fromSynonym = $this->resolveViaSynonymGroup($canonical, $vocabulary);
        if ($fromSynonym !== null) {
            return ['name' => $fromSynonym, 'value' => $value];
        }

        $fuzzy = $this->resolveFuzzy($canonical, $vocabulary);
        if ($fuzzy !== null) {
            return ['name' => $fuzzy, 'value' => $value];
        }

        // Scoped vocab present but name unknown → match by value across product attrs.
        return [
            'name' => $canonical !== '' ? $canonical : $name,
            'value' => $value,
            'value_only' => true,
        ];
    }

    /**
     * @param  list<string>  $vocabulary
     */
    private function findExactInVocabulary(string $name, array $vocabulary): ?string
    {
        $needle = $this->normalizeKey($name);
        foreach ($vocabulary as $candidate) {
            if ($this->normalizeKey($candidate) === $needle) {
                return $candidate;
            }
        }

        // Alias-only hit (e.g. color → Цвет) when Цвет is in vocab.
        $aliased = $this->names->canonicalize($name);
        $aliasKey = $this->normalizeKey($aliased);
        foreach ($vocabulary as $candidate) {
            if ($this->normalizeKey($candidate) === $aliasKey) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $vocabulary
     */
    private function resolveViaSynonymGroup(string $name, array $vocabulary): ?string
    {
        $needle = $this->normalizeKey($name);
        /** @var array<string, list<string>> $groups */
        $groups = config('attributes.synonym_groups', []);

        $matchedGroup = null;
        foreach ($groups as $aliases) {
            foreach ($aliases as $alias) {
                $aliasKey = $this->normalizeKey($alias);
                if ($aliasKey === $needle || str_contains($needle, $aliasKey) || str_contains($aliasKey, $needle)) {
                    // Avoid tiny aliases matching everything; require length >= 3 or exact.
                    $aliasTooShortForFuzzy = mb_strlen($aliasKey) < 3 && $aliasKey !== $needle;
                    if ($aliasTooShortForFuzzy) {
                        continue;
                    }
                    $matchedGroup = $aliases;
                    break 2;
                }
            }
        }

        if ($matchedGroup === null) {
            return null;
        }

        $hits = [];
        foreach ($vocabulary as $candidate) {
            $candKey = $this->normalizeKey($candidate);
            foreach ($matchedGroup as $alias) {
                $aliasKey = $this->normalizeKey($alias);
                if ($candKey === $aliasKey || str_contains($candKey, $aliasKey)) {
                    $hits[] = $candidate;
                    break;
                }
            }
        }

        $hits = array_values(array_unique($hits));
        if (count($hits) === 1) {
            return $hits[0];
        }

        // Prefer exact synonym label present in vocab (Экран over longer names).
        foreach ($matchedGroup as $alias) {
            foreach ($hits as $hit) {
                if ($this->normalizeKey($hit) === $this->normalizeKey($alias)) {
                    return $hit;
                }
            }
        }

        return count($hits) > 0 ? $hits[0] : null;
    }

    /**
     * @param  list<string>  $vocabulary
     */
    private function resolveFuzzy(string $name, array $vocabulary): ?string
    {
        $needle = $this->normalizeKey($name);
        if ($needle === '' || mb_strlen($needle) < 3) {
            return null;
        }

        $best = null;
        $bestPct = 0.0;
        foreach ($vocabulary as $candidate) {
            $candKey = $this->normalizeKey($candidate);
            similar_text($needle, $candKey, $pct);
            if ($pct > $bestPct) {
                $bestPct = $pct;
                $best = $candidate;
            }
        }

        return $bestPct >= self::MIN_FUZZY_MATCH_PERCENT ? $best : null;
    }

    private function normalizeKey(string $value): string
    {
        return \App\Support\RuText::fold($value);
    }
}
