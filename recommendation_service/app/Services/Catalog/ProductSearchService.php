<?php

namespace App\Services\Catalog;

use App\Models\ProductCatalog;
use App\Services\Catalog\DTOs\SearchHit;
use App\Services\Catalog\DTOs\SearchResult;
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
        private readonly AttributeNameNormalizer $attributeNames = new AttributeNameNormalizer,
        private readonly AttributeValueMatcher $attributeValues = new AttributeValueMatcher,
        private readonly AttributeValueNormalizer $valueNormalizer = new AttributeValueNormalizer,
    ) {}

    public function productSatisfiesAttribute(
        ProductCatalog $product,
        string $name,
        string $value,
        bool $valueOnly = false,
    ): bool {
        return $this->productMatchesAttribute($product, $name, $value, $valueOnly);
    }

    /**
     * @param  list<float>|null  $precomputedVector
     * @return list<SearchHit>
     */
    public function search(SearchFilters $filters, int $limit = 5, ?array $precomputedVector = null): array
    {
        return $this->searchDetailed($filters, $limit, $precomputedVector)->hits;
    }

    /**
     * @param  list<float>|null  $precomputedVector
     */
    public function searchDetailed(SearchFilters $filters, int $limit = 5, ?array $precomputedVector = null): SearchResult
    {
        $limit = max(1, min($limit, 20));
        $vector = $precomputedVector ?? $this->llm->embed(
            $filters->query ?: 'recommended products',
            'RETRIEVAL_QUERY'
        );
        $literal = $this->toVectorLiteral($vector);

        $softAttrs = $filters->attributes;
        $hardAttrs = $filters->requiredAttributes;
        $preferredAttrs = $filters->preferredAttributes;

        // Preferred (color/brand/material): try as hard first, fall back to soft re-rank.
        if ($preferredAttrs !== []) {
            $hardAttempt = new SearchFilters(
                storeId: $filters->storeId,
                storeName: $filters->storeName,
                categoryName: $filters->categoryName,
                inStock: $filters->inStock,
                priceMin: $filters->priceMin,
                priceMax: $filters->priceMax,
                query: $filters->query,
                attributes: $softAttrs,
                requiredAttributes: array_merge($hardAttrs, $preferredAttrs),
                preferredAttributes: [],
            );
            $hits = $this->searchWithHardAndSoft($hardAttempt, $literal, $limit);
            if ($hits !== []) {
                return new SearchResult(hits: $hits, preferredRelaxed: false);
            }

            // Fallback: preferred become soft so we still return near matches.
            $filters = new SearchFilters(
                storeId: $filters->storeId,
                storeName: $filters->storeName,
                categoryName: $filters->categoryName,
                inStock: $filters->inStock,
                priceMin: $filters->priceMin,
                priceMax: $filters->priceMax,
                query: $filters->query,
                attributes: array_merge($preferredAttrs, $softAttrs),
                requiredAttributes: $hardAttrs,
                preferredAttributes: [],
            );

            return new SearchResult(
                hits: $this->searchWithHardAndSoft($filters, $literal, $limit),
                preferredRelaxed: true,
            );
        }

        return new SearchResult(
            hits: $this->searchWithHardAndSoft($filters, $literal, $limit),
            preferredRelaxed: false,
        );
    }

    /**
     * @return list<SearchHit>
     */
    private function searchWithHardAndSoft(SearchFilters $filters, string $literal, int $limit): array
    {
        $hasSoft = $filters->attributes !== [];
        $hasHard = $filters->requiredAttributes !== [];

        $fetchLimit = $limit;
        if ($hasHard) {
            $fetchLimit = max($fetchLimit, self::HARD_ATTR_POOL);
        } elseif ($hasSoft) {
            $fetchLimit = max($fetchLimit, self::SOFT_ATTR_POOL);
        }

        $hits = $this->vectorSearch($filters, $literal, $fetchLimit);

        // Widen recall when several soft attrs are unlikely to co-occur in the vector top-k.
        if (! $hasHard && count($filters->attributes) >= 2) {
            $fallback = $this->attributeFirstFallback(
                $filters,
                $literal,
                self::SOFT_ATTR_POOL,
                $filters->attributes,
            );
            $hits = $this->mergeHitsByProductId($hits, $fallback);
        }

        if ($hasHard) {
            $matched = $this->hardFilterByAttributes($hits, $filters->requiredAttributes);
            if ($matched === []) {
                $hits = $this->attributeFirstFallback(
                    $filters,
                    $literal,
                    self::ATTR_FALLBACK_POOL,
                    $filters->requiredAttributes,
                );
                $matched = $this->hardFilterByAttributes($hits, $filters->requiredAttributes);
            }
            $hits = $matched;
        }

        if ($hits === []) {
            return [];
        }

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
     * Wider structured scan with JSON text prefilter when vector pool missed attributes.
     *
     * @param  list<array{name: string, value: string}>  $attributes
     * @return list<SearchHit>
     */
    private function attributeFirstFallback(
        SearchFilters $filters,
        string $literal,
        int $fetchLimit,
        array $attributes,
    ): array {
        $builder = $this->baseFilteredQuery($filters);
        $this->applyAttributeSqlPrefilter($builder, $attributes);

        $rows = $builder
            ->select('id')
            ->selectRaw('embedding <=> ?::vector AS distance', [$literal])
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($fetchLimit)
            ->get();

        return $this->hydrateHits($rows);
    }

    /**
     * @param  list<SearchHit>  $primary
     * @param  list<SearchHit>  $extra
     * @return list<SearchHit>
     */
    private function mergeHitsByProductId(array $primary, array $extra): array
    {
        $byId = [];
        foreach (array_merge($primary, $extra) as $hit) {
            $id = (int) $hit->product->id;
            if (! isset($byId[$id]) || $hit->distance < $byId[$id]->distance) {
                $byId[$id] = $hit;
            }
        }

        return array_values($byId);
    }

    /**
     * @param  Collection<int, object{id: int|string, distance?: float|string|null}>|\Illuminate\Database\Eloquent\Collection<int, ProductCatalog>  $rows
     * @return list<SearchHit>
     */
    private function hydrateHits(Collection|\Illuminate\Database\Eloquent\Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = array_values($rows->pluck('id')->all());
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
            $value = trim((string) $attr['value']);
            if ($value === '') {
                continue;
            }

            $number = $this->valueNormalizer->extractNumber($value);
            $token = $number !== null
                ? str_replace(',', '.', (string) $number)
                : $this->valueNormalizer->normalizeForCompare($value);

            if ($token === '') {
                continue;
            }

            // Align with PHP matcher: lower + ё→е on JSON text; token already stemmed/stripped.
            $builder->whereRaw(
                "replace(lower(attributes::text), 'ё', 'е') LIKE ?",
                ['%'.$this->escapeLike($token).'%']
            );
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

        return $hits;
    }

    /**
     * @param  list<array{name: string, value: string, value_only?: bool}>  $attributes
     */
    private function attributeMatchScore(ProductCatalog $product, array $attributes): int
    {
        $score = 0;
        foreach ($attributes as $attr) {
            $valueOnly = (bool) ($attr['value_only'] ?? false);
            if ($this->productMatchesAttribute(
                $product,
                (string) $attr['name'],
                (string) $attr['value'],
                $valueOnly,
            )) {
                $score++;
            }
        }

        return $score;
    }

    private function productMatchesAttribute(
        ProductCatalog $product,
        string $name,
        string $value,
        bool $valueOnly = false,
    ): bool {
        if (trim($value) === '') {
            return false;
        }

        $rows = $product->attributes ?? [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $attrName = (string) ($row['name'] ?? '');
            $attrValue = (string) ($row['value'] ?? '');
            if ($attrName === '' || $attrValue === '') {
                continue;
            }
            if (! $valueOnly) {
                if ($name === '' || ! $this->attributeNames->namesMatch($attrName, $name)) {
                    continue;
                }
            }
            // value_only: match against the product attr name's strategy (or default text).
            $strategyName = $valueOnly ? $attrName : $name;
            if ($this->attributeValues->matches($strategyName, $attrValue, $value)) {
                return true;
            }
        }

        return false;
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
