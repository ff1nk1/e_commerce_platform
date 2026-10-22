<?php

namespace App\Services\Catalog;

use App\Support\SqlLike;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Distinct attribute names from the local catalog index, optionally scoped by category.
 */
class AttributeVocabulary
{
    public const CACHE_PREFIX = 'attr_vocab:v1:';

    /**
     * @param  array{key: string, category_ilike: string}|null  $scope
     * @return list<string>
     */
    public function namesForScope(?array $scope): array
    {
        if ($scope === null) {
            return [];
        }

        $ttl = max(60, (int) config('attributes.vocab_cache_ttl_seconds', 600));
        $cacheKey = self::CACHE_PREFIX.$scope['key'];

        /** @var list<string> $names */
        $names = Cache::remember($cacheKey, $ttl, function () use ($scope): array {
            return $this->loadNames($scope['category_ilike']);
        });

        $cap = max(1, (int) config('attributes.vocab_prompt_cap', 30));

        return array_slice($names, 0, $cap);
    }

    public function forgetAll(): void
    {
        /** @var list<array{key: string}> $types */
        $types = config('attributes.product_types', []);
        foreach ($types as $type) {
            Cache::forget(self::CACHE_PREFIX.$type['key']);
        }
    }

    public function forgetScope(string $scopeKey): void
    {
        Cache::forget(self::CACHE_PREFIX.$scopeKey);
    }

    /**
     * @return list<string>
     */
    private function loadNames(string $categoryIlike): array
    {
        $needle = '%'.$this->escapeLike(mb_strtolower($categoryIlike)).'%';

        $rows = DB::table('product_catalog')
            ->where('is_published', true)
            ->whereRaw("replace(lower(coalesce(category_name, '')), 'ё', 'е') LIKE ?", [$needle])
            ->whereNotNull('attributes')
            ->pluck('attributes');

        $counts = [];
        foreach ($rows as $raw) {
            $attrs = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! is_array($attrs)) {
                continue;
            }
            foreach ($attrs as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        arsort($counts);

        return array_keys($counts);
    }

    private function escapeLike(string $value): string
    {
        return SqlLike::escape($value);
    }
}
