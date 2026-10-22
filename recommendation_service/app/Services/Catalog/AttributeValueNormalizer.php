<?php

namespace App\Services\Catalog;

use App\Support\RuText;

/**
 * Config-driven value normalization helpers (strip patterns / stems live in attributes.php).
 */
class AttributeValueNormalizer
{
    public function normalizeForCompare(string $value, bool $applyStem = true): string
    {
        $normalized = RuText::fold($value);
        $normalized = $this->applyStripPatterns($normalized);
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        if (! $applyStem) {
            return $normalized;
        }

        return $this->applyStemSuffixes($normalized);
    }

    public function extractNumber(string $value): ?float
    {
        $prepared = $this->normalizeForCompare($value, applyStem: false);
        if (preg_match('/(\d+(?:[.,]\d+)?)/u', $prepared, $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    public function numbersMatch(string $left, string $right, ?float $epsilon = null): bool
    {
        $a = $this->extractNumber($left);
        $b = $this->extractNumber($right);
        if ($a === null || $b === null) {
            return false;
        }

        $eps = $epsilon ?? (float) config('attributes.value_match.number_epsilon', 0.05);

        return abs($a - $b) <= $eps;
    }

    private function applyStripPatterns(string $value): string
    {
        /** @var list<string> $patterns */
        $patterns = config('attributes.value_match.strip_patterns', []);
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $value = preg_replace($pattern, '', $value) ?? $value;
        }

        return $value;
    }

    private function applyStemSuffixes(string $value): string
    {
        /** @var list<string> $suffixes */
        $suffixes = config('attributes.value_match.stem_suffixes', []);
        if ($suffixes === []) {
            return $value;
        }

        // Longest first so "ими" wins over "и".
        usort($suffixes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $escaped = array_map(static fn (string $s): string => preg_quote($s, '/'), $suffixes);
        $pattern = '/('.implode('|', $escaped).')$/u';
        $stripped = preg_replace($pattern, '', $value);

        return is_string($stripped) && $stripped !== '' ? $stripped : $value;
    }
}
