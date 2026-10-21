<?php

namespace App\Services\Llm\DTOs;

readonly class SearchFilters
{
    /**
     * @param  list<array{name: string, value: string}>  $attributes  Soft preferences (re-rank only)
     * @param  list<array{name: string, value: string}>  $requiredAttributes  Hard constraints (must match)
     * @param  list<array{name: string, value: string}>  $preferredAttributes  Semi-hard (try hard, then soft fallback)
     */
    public function __construct(
        public ?int $storeId = null,
        public ?string $storeName = null,
        public ?string $categoryName = null,
        public ?bool $inStock = null,
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        public ?string $query = null,
        public array $attributes = [],
        public array $requiredAttributes = [],
        public array $preferredAttributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            storeId: self::nullableInt($data['store_id'] ?? null),
            storeName: self::nullableString($data['store_name'] ?? null),
            categoryName: self::nullableString($data['category_name'] ?? null),
            inStock: self::nullableBool($data['in_stock'] ?? null),
            priceMin: self::nullableFloat($data['price_min'] ?? null),
            priceMax: self::nullableFloat($data['price_max'] ?? null),
            query: self::nullableString($data['query'] ?? null),
            attributes: self::parseAttributes($data['attributes'] ?? []),
            requiredAttributes: self::parseAttributes($data['required_attributes'] ?? []),
            preferredAttributes: self::parseAttributes($data['preferred_attributes'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'store_id' => $this->storeId,
            'store_name' => $this->storeName,
            'category_name' => $this->categoryName,
            'in_stock' => $this->inStock,
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'query' => $this->query,
            'attributes' => $this->attributes,
            'required_attributes' => $this->requiredAttributes,
            'preferred_attributes' => $this->preferredAttributes,
        ];
    }

    /**
     * True when the filters imply a catalog search (ignore default in_stock alone).
     */
    public function hasSearchIntent(): bool
    {
        return $this->query !== null
            || $this->storeId !== null
            || $this->storeName !== null
            || $this->categoryName !== null
            || $this->priceMin !== null
            || $this->priceMax !== null
            || $this->attributes !== []
            || $this->requiredAttributes !== []
            || $this->preferredAttributes !== [];
    }

    /**
     * @return list<array{name: string, value: string, value_only?: bool}>
     */
    private static function parseAttributes(mixed $rawAttributes): array
    {
        $attributes = [];
        if (! is_array($rawAttributes)) {
            return $attributes;
        }

        foreach ($rawAttributes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            $valueOnly = (bool) ($row['value_only'] ?? false);
            if ($value === '') {
                continue;
            }
            if ($name === '' && ! $valueOnly) {
                continue;
            }
            $attr = ['name' => $name, 'value' => $value];
            if ($valueOnly) {
                $attr['value_only'] = true;
            }
            $attributes[] = $attr;
        }

        return $attributes;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        return null;
    }
}
