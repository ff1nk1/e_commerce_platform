<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchProductsRequest extends FormRequest
{
    public const MAX_PAGE = 200;

    public const MAX_CATEGORY_IDS = 50;

    public const MAX_ATTR_KEYS = 20;

    public const MAX_ATTR_VALUES_PER_KEY = 20;

    public const MAX_ATTR_STRING_LENGTH = 64;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'array', 'max:'.self::MAX_CATEGORY_IDS],
            'category_id.*' => ['integer'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'in_stock' => ['nullable'],
            'attr' => ['nullable', 'array', 'max:'.self::MAX_ATTR_KEYS],
            'attr.*' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort' => ['nullable', 'string', Rule::in(['relevance', 'price_asc', 'price_desc', 'new'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $categoryId = $this->input('category_id');
        if ($categoryId !== null && ! is_array($categoryId)) {
            $this->merge([
                'category_id' => $categoryId === '' ? [] : [$categoryId],
            ]);
        }
    }

    /**
     * @return array{
     *     q: string|null,
     *     category_ids: list<int>,
     *     min_price: float|null,
     *     max_price: float|null,
     *     in_stock: bool|null,
     *     attributes: array<string, list<string>>,
     *     page: int,
     *     per_page: int,
     *     sort: string
     * }
     */
    public function searchParams(): array
    {
        $categoryIds = $this->input('category_id', []);
        if (! is_array($categoryIds)) {
            $categoryIds = $categoryIds === null || $categoryIds === '' ? [] : [$categoryIds];
        }
        $categoryIds = array_values(array_unique(array_map(
            'intval',
            array_filter($categoryIds, fn ($v) => $v !== null && $v !== '')
        )));
        $categoryIds = array_slice($categoryIds, 0, self::MAX_CATEGORY_IDS);

        $inStockRaw = $this->input('in_stock');
        $inStock = null;
        if ($inStockRaw !== null && $inStockRaw !== '') {
            $inStock = filter_var($inStockRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($inStock === null && in_array((string) $inStockRaw, ['1', '0'], true)) {
                $inStock = (string) $inStockRaw === '1';
            }
        }

        $attributes = [];
        $attrInput = $this->input('attr', []);
        if (is_array($attrInput)) {
            $keyCount = 0;
            foreach ($attrInput as $name => $value) {
                if ($keyCount >= self::MAX_ATTR_KEYS) {
                    break;
                }

                $name = mb_substr(trim((string) $name), 0, self::MAX_ATTR_STRING_LENGTH);
                if ($name === '') {
                    continue;
                }
                $values = is_array($value) ? $value : [$value];
                $normalized = [];
                foreach ($values as $v) {
                    if (count($normalized) >= self::MAX_ATTR_VALUES_PER_KEY) {
                        break;
                    }
                    $v = mb_substr(trim((string) $v), 0, self::MAX_ATTR_STRING_LENGTH);
                    if ($v !== '') {
                        $normalized[] = $v;
                    }
                }
                if ($normalized !== []) {
                    $attributes[$name] = array_values(array_unique($normalized));
                    $keyCount++;
                }
            }
        }

        $q = $this->input('q');
        $q = is_string($q) ? trim($q) : null;
        if ($q === '') {
            $q = null;
        }

        $sort = (string) ($this->input('sort') ?: ($q ? 'relevance' : 'new'));

        return [
            'q' => $q,
            'category_ids' => $categoryIds,
            'min_price' => $this->filled('min_price') ? (float) $this->input('min_price') : null,
            'max_price' => $this->filled('max_price') ? (float) $this->input('max_price') : null,
            'in_stock' => $inStock,
            'attributes' => $attributes,
            'page' => min(self::MAX_PAGE, max(1, (int) $this->input('page', 1))),
            'per_page' => min(50, max(1, (int) $this->input('per_page', 20))),
            'sort' => $sort,
        ];
    }

    /**
     * Loadtest / synthetic traffic: still hits Kafka, but analytic_service skips ClickHouse.
     * Honored only when ALLOW_SKIP_ANALYTICS=true (never trust public clients in prod).
     */
    public function shouldSkipAnalytics(): bool
    {
        if (! (bool) config('opensearch.allow_skip_analytics', false)) {
            return false;
        }

        $raw = strtolower(trim((string) $this->header('X-Skip-Analytics', '')));

        return in_array($raw, ['1', 'true', 'yes'], true);
    }
}
