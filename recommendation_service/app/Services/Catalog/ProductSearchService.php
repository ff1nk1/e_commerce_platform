<?php

namespace App\Services\Catalog;

use App\Models\ProductCatalog;
use App\Services\Catalog\DTOs\SearchHit;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\SearchFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductSearchService
{
    private const SOFT_ATTR_POOL = 80;

    private const HARD_ATTR_POOL = 200;

    private const ATTR_FALLBACK_POOL = 500;

    public function __construct(
        private readonly LlmClient $llm,
    ) {}

    /**
     * @param  list<float>|null  $precomputedVector
     * @return list<SearchHit>
     */
    public function search(SearchFilters $filters, int $limit = 5, ?array $precomputedVector = null): array
    {
        $limit = max(1, min($limit, 20));
        $vector = $precomputedVector ?? $this->llm->embed(
            $filters->query ?: 'recommended products',
            'RETRIEVAL_QUERY'
        );
        $literal = $this->toVectorLiteral($vector);

        $hasSoft = $filters->attributes !== [];
        $hasHard = $filters->requiredAttributes !== [];

        $fetchLimit = $limit;
        if ($hasHard) {
            $fetchLimit = max($fetchLimit, self::HARD_ATTR_POOL);
        } elseif ($hasSoft) {
            $fetchLimit = min(self::SOFT_ATTR_POOL, max($limit * 4, $limit));
        }

        $hits = $this->vectorSearch($filters, $literal, $fetchLimit);

        if ($hasHard) {
            $matched = $this->hardFilterByAttributes($hits, $filters->requiredAttributes);
            if ($matched === []) {
                // Hybrid recall: structured filters + SQL attribute prefilter, then exact PHP match.
                $hits = $this->attributeFirstFallback($filters, $literal, self::ATTR_FALLBACK_POOL);
                $matched = $this->hardFilterByAttributes($hits, $filters->requiredAttributes);
            }
            $hits = $matched;
        }

        if ($hits === []) {
            return [];
        }

        // Soft re-rank uses soft attrs plus required (prefer full match).
        $rerankAttrs = array_merge($filters->requiredAttributes, $filters->attributes);
        if ($rerankAttrs !== []) {
            $hits = $this->softRerankByAttributes($hits, $rerankAttrs);
        }

        return array_slice($hits, 0, $limit);
    }

    /**
     * @return list<SearchHit>
     */
    private function vectorSearch(SearchFilters $filters, string $literal, int $fetchLimit): array
    {
        $builder = $this->baseFilteredQuery($filters);

        $rows = $builder
            ->select('id')
            ->selectRaw('embedding <=> ?::vector AS distance', [$literal])
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($fetchLimit)
            ->get();

        return $this->hydrateHits($rows);
    }

    /**
     * Wider structured scan with JSON text prefilter when vector pool missed hard attrs.
     *
     * @return list<SearchHit>
     */
    private function attributeFirstFallback(SearchFilters $filters, string $literal, int $fetchLimit): array
    {
        $builder = $this->baseFilteredQuery($filters);
        $this->applyAttributeSqlPrefilter($builder, $filters->requiredAttributes);

        $rows = $builder
            ->select('id')
            ->selectRaw('embedding <=> ?::vector AS distance', [$literal])
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($fetchLimit)
            ->get();

        return $this->hydrateHits($rows);
    }

    /**
     * @param  Collection<int, object{id: mixed, distance?: mixed}>  $rows
     * @return list<SearchHit>
     */
    private function hydrateHits(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->all();
        $distanceById = [];
        foreach ($rows as $row) {
            $distanceById[(int) $row->id] = (float) ($row->distance ?? INF);
        }

        $products = ProductCatalog::query()->whereIn('id', $ids)->get()->keyBy('id');

        $hits = [];
        foreach ($ids as $id) {
            if (! isset($products[$id])) {
                continue;
            }
            $hits[] = new SearchHit(
                product: $products[$id],
                distance: $distanceById[(int) $id] ?? INF,
            );
        }

        return $hits;
    }

    /**
     * @return Builder<ProductCatalog>
     */
    private function baseFilteredQuery(SearchFilters $filters): Builder
    {
        $builder = ProductCatalog::query()
            ->where('is_published', true)
            ->whereNotNull('embedding');

        if ($filters->storeId !== null) {
            $builder->where('store_id', $filters->storeId);
        }
        if ($filters->storeName !== null) {
            $this->applyIlikeContains($builder, 'store_name', $filters->storeName);
        }
        if ($filters->categoryName !== null) {
            $this->applyIlikeContains($builder, 'category_name', $filters->categoryName);
        }
        if ($filters->inStock !== null) {
            $builder->where('in_stock', $filters->inStock);
        }
        if ($filters->priceMin !== null) {
            $builder->where('price', '>=', $filters->priceMin);
        }
        if ($filters->priceMax !== null) {
            $builder->where('price', '<=', $filters->priceMax);
        }

        return $builder;
    }

    /**
     * Cheap JSON text prefilter to shrink the fallback scan; PHP matcher is authoritative.
     *
     * @param  Builder<ProductCatalog>  $builder
     * @param  list<array{name: string, value: string}>  $attributes
     */
    private function applyAttributeSqlPrefilter(Builder $builder, array $attributes): void
    {
        foreach ($attributes as $attr) {
            $value = trim((string) ($attr['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            // Prefer the numeric token for sizes ("28", "от 27" → 27).
            $token = $value;
            if (preg_match('/(\d+(?:[.,]\d+)?)/u', $value, $matches) === 1) {
                $token = $matches[1];
            }
            $builder->whereRaw('attributes::text ILIKE ?', ['%'.$this->escapeLike($token).'%']);
        }
    }

    /**
     * @param  list<SearchHit>  $hits
     * @param  list<array{name: string, value: string}>  $attributes
     * @return list<SearchHit>
     */
    private function hardFilterByAttributes(array $hits, array $attributes): array
    {
        if ($attributes === []) {
            return $hits;
        }

        $kept = [];
        foreach ($hits as $hit) {
            if ($this->attributeMatchScore($hit->product, $attributes) === count($attributes)) {
                $kept[] = $hit;
            }
        }

        return $kept;
    }

    /**
     * @param  list<SearchHit>  $hits
     * @param  list<array{name: string, value: string}>  $attributes
     * @return list<SearchHit>
     */
    private function softRerankByAttributes(array $hits, array $attributes): array
    {
        usort($hits, function (SearchHit $a, SearchHit $b) use ($attributes): int {
            $scoreA = $this->attributeMatchScore($a->product, $attributes);
            $scoreB = $this->attributeMatchScore($b->product, $attributes);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return $a->distance <=> $b->distance;
        });

        return array_values($hits);
    }

    /**
     * @param  list<array{name: string, value: string}>  $attributes
     */
    private function attributeMatchScore(ProductCatalog $product, array $attributes): int
    {
        $score = 0;
        foreach ($attributes as $attr) {
            if ($this->productMatchesAttribute($product, $attr['name'], $attr['value'])) {
                $score++;
            }
        }

        return $score;
    }

    private function productMatchesAttribute(ProductCatalog $product, string $name, string $value): bool
    {
        $nameNeedle = $this->normalizeCaseYo($name);
        $valueNeedle = $this->normalizeAttrValue($value);
        if ($nameNeedle === '' || $valueNeedle === '') {
            return false;
        }

        $rows = is_array($product->attributes) ? $product->attributes : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $attrName = $this->normalizeCaseYo((string) ($row['name'] ?? ''));
            $attrValue = (string) ($row['value'] ?? '');
            if ($attrName === '' || $attrValue === '') {
                continue;
            }
            if (! str_contains($attrName, $nameNeedle)) {
                continue;
            }
            if ($this->attributeValuesMatch($attrName, $attrValue, $value, $valueNeedle)) {
                return true;
            }
        }

        return false;
    }

    private function attributeValuesMatch(
        string $productAttrName,
        string $productValue,
        string $requestedRaw,
        string $requestedNormalized,
    ): bool {
        if ($this->isSizeAttribute($productAttrName)) {
            $constraint = $this->parseSizeConstraint($requestedRaw);
            $productSize = $this->parseSizeNumber($productValue);
            if ($constraint !== null && $productSize !== null) {
                if ($productSize < $constraint['min']) {
                    return false;
                }
                if ($constraint['max'] !== null && $productSize > $constraint['max']) {
                    return false;
                }

                return true;
            }
        }

        $normalizedProduct = $this->normalizeCaseYo($productValue);

        return str_contains($normalizedProduct, $requestedNormalized);
    }

    private function isSizeAttribute(string $normalizedName): bool
    {
        return str_contains($normalizedName, 'размер')
            || str_contains($normalizedName, 'size');
    }

    /**
     * @return array{min: float, max: float|null}|null
     */
    private function parseSizeConstraint(string $value): ?array
    {
        $normalized = $this->normalizeCaseYo($value);

        if (preg_match('/от\s*(\d+(?:[.,]\d+)?)/u', $normalized, $matches) === 1) {
            return [
                'min' => (float) str_replace(',', '.', $matches[1]),
                'max' => null,
            ];
        }

        if (preg_match('/^(\d+(?:[.,]\d+)?)$/u', trim($normalized), $matches) === 1) {
            $n = (float) str_replace(',', '.', $matches[1]);

            return ['min' => $n, 'max' => $n];
        }

        return null;
    }

    private function parseSizeNumber(string $value): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)/u', $this->normalizeCaseYo($value), $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    /**
     * @param  Builder<ProductCatalog>  $builder
     */
    private function applyIlikeContains(Builder $builder, string $column, string $value): void
    {
        $needle = $this->normalizeCaseYo($value);
        if ($needle === '') {
            return;
        }

        $builder->whereRaw(
            "replace(lower({$column}), 'ё', 'е') LIKE ?",
            ['%'.$this->escapeLike($needle).'%']
        );
    }

    private function normalizeCaseYo(string $value): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim($value)));
    }

    private function normalizeAttrValue(string $value): string
    {
        $normalized = $this->normalizeCaseYo($value);
        $stripped = preg_replace(
            '/(ого|его|ому|ему|ой|ый|ий|ая|ое|ые|их|ую|ими|ыми)$/u',
            '',
            $normalized
        );

        return is_string($stripped) && $stripped !== '' ? $stripped : $normalized;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  list<float>  $vector
     */
    private function toVectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn (float $v): string => sprintf('%.8F', $v),
            $vector
        )).']';
    }
}
