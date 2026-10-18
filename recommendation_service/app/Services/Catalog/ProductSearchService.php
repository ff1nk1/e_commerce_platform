<?php

namespace App\Services\Catalog;

use App\Models\ProductCatalog;
use App\Services\Catalog\DTOs\SearchHit;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\SearchFilters;
use Illuminate\Database\Eloquent\Builder;

class ProductSearchService
{
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
        $literal = '['.implode(',', array_map(
            static fn (float $v): string => sprintf('%.8F', $v),
            $vector
        )).']';

        // Fetch a wider pool when attributes need soft re-ranking in PHP.
        $fetchLimit = $filters->attributes !== []
            ? min(20, max($limit * 4, $limit))
            : $limit;

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

        $rows = $builder
            ->select('id')
            ->selectRaw('embedding <=> ?::vector AS distance', [$literal])
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($fetchLimit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->all();
        $distanceById = [];
        foreach ($rows as $row) {
            $distanceById[(int) $row->id] = (float) $row->distance;
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

        if ($filters->attributes !== []) {
            $hits = $this->softRerankByAttributes($hits, $filters->attributes);
        }

        return array_slice($hits, 0, $limit);
    }

    /**
     * Prefer products that soft-match extracted attributes; never drop the whole set.
     *
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
}
