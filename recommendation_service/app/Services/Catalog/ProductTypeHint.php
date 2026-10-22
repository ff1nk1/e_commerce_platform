<?php

namespace App\Services\Catalog;

/**
 * Cheap product-type hint before LLM extract (keywords + sticky filters).
 */
class ProductTypeHint
{
    /**
     * @param  array<string, mixed>|null  $previousFilters
     * @return array{key: string, category_ilike: string}|null
     */
    public function resolve(string $messageText, ?array $previousFilters = null, ?string $clientCategoryName = null): ?array
    {
        $fromMessage = $this->matchKeywords($messageText);
        if ($fromMessage !== null) {
            return $fromMessage;
        }

        if ($clientCategoryName !== null && trim($clientCategoryName) !== '') {
            $fromClient = $this->matchKeywords($clientCategoryName)
                ?? $this->scopeFromCategoryFragment($clientCategoryName);
            if ($fromClient !== null) {
                return $fromClient;
            }
        }

        if (is_array($previousFilters)) {
            $stickyCategory = trim((string) ($previousFilters['category_name'] ?? ''));
            if ($stickyCategory !== '') {
                $fromSticky = $this->matchKeywords($stickyCategory)
                    ?? $this->scopeFromCategoryFragment($stickyCategory);
                if ($fromSticky !== null) {
                    return $fromSticky;
                }
            }

            $stickyQuery = trim((string) ($previousFilters['query'] ?? ''));
            if ($stickyQuery !== '') {
                $fromQuery = $this->matchKeywords($stickyQuery);
                if ($fromQuery !== null) {
                    return $fromQuery;
                }
            }
        }

        return null;
    }

    /**
     * @return array{key: string, category_ilike: string}|null
     */
    private function matchKeywords(string $text): ?array
    {
        $normalized = \App\Support\RuText::fold($text);
        if ($normalized === '') {
            return null;
        }

        /** @var list<array{key: string, patterns: list<string>, category_ilike: string}> $types */
        $types = config('attributes.product_types', []);

        foreach ($types as $type) {
            foreach ($type['patterns'] as $pattern) {
                if (preg_match('/(^|[^\p{L}\p{N}])(?:'.$pattern.')($|[^\p{L}\p{N}])/ui', $normalized) === 1) {
                    return [
                        'key' => (string) $type['key'],
                        'category_ilike' => (string) $type['category_ilike'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array{key: string, category_ilike: string}|null
     */
    private function scopeFromCategoryFragment(string $categoryName): ?array
    {
        $fragment = trim($categoryName);
        if ($fragment === '') {
            return null;
        }

        return [
            'key' => 'category:'.mb_strtolower($fragment),
            'category_ilike' => $fragment,
        ];
    }
}
