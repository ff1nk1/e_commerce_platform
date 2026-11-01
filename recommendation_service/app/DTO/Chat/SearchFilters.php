<?php

declare(strict_types=1);

namespace App\DTO\Chat;

use App\DTO\Catalog\AttributeConstraint;

final readonly class SearchFilters
{
    /**
     * @param  list<AttributeConstraint|array{name?: string, value?: string, value_only?: bool}>  $attributes  Soft preferences (re-rank only)
     * @param  list<AttributeConstraint|array{name?: string, value?: string, value_only?: bool}>  $requiredAttributes  Hard constraints (must match)
     * @param  list<AttributeConstraint|array{name?: string, value?: string, value_only?: bool}>  $preferredAttributes  Semi-hard (try hard, then soft fallback)
     */
    public function __construct(
        public ?int $storeId = null,
        public ?string $storeName = null,
        public ?string $categoryName = null,
        public ?bool $inStock = null,
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        public ?string $query = null,
        array $attributes = [],
        array $requiredAttributes = [],
        array $preferredAttributes = [],
    ) {
        $this->attributes = $this->constraints($attributes);
        $this->requiredAttributes = $this->constraints($requiredAttributes);
        $this->preferredAttributes = $this->constraints($preferredAttributes);
    }

    /** @var list<AttributeConstraint> */
    public array $attributes;

    /** @var list<AttributeConstraint> */
    public array $requiredAttributes;

    /** @var list<AttributeConstraint> */
    public array $preferredAttributes;

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
            'attributes' => $this->constraintsToArray($this->attributes),
            'required_attributes' => $this->constraintsToArray($this->requiredAttributes),
            'preferred_attributes' => $this->constraintsToArray($this->preferredAttributes),
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
     * Preferred attrs promoted into hard constraints (empty preferred bucket).
     */
    public function withPreferredAsHard(): self
    {
        return $this->withAttributeBuckets(
            attributes: $this->attributes,
            requiredAttributes: array_merge($this->requiredAttributes, $this->preferredAttributes),
            preferredAttributes: [],
        );
    }

    /**
     * Preferred attrs demoted into soft re-rank (empty preferred bucket).
     */
    public function withPreferredAsSoft(): self
    {
        return $this->withAttributeBuckets(
            attributes: array_merge($this->preferredAttributes, $this->attributes),
            requiredAttributes: $this->requiredAttributes,
            preferredAttributes: [],
        );
    }

    /**
     * @param  list<AttributeConstraint>  $attributes
     * @param  list<AttributeConstraint>  $requiredAttributes
     * @param  list<AttributeConstraint>  $preferredAttributes
     */
    public function withAttributeBuckets(
        array $attributes,
        array $requiredAttributes,
        array $preferredAttributes,
    ): self {
        return new self(
            storeId: $this->storeId,
            storeName: $this->storeName,
            categoryName: $this->categoryName,
            inStock: $this->inStock,
            priceMin: $this->priceMin,
            priceMax: $this->priceMax,
            query: $this->query,
            attributes: $attributes,
            requiredAttributes: $requiredAttributes,
            preferredAttributes: $preferredAttributes,
        );
    }

    public function withoutStoreName(): self
    {
        return new self(
            storeId: $this->storeId,
            categoryName: $this->categoryName,
            inStock: $this->inStock,
            priceMin: $this->priceMin,
            priceMax: $this->priceMax,
            query: $this->query,
            attributes: $this->attributes,
            requiredAttributes: $this->requiredAttributes,
            preferredAttributes: $this->preferredAttributes,
        );
    }

    public function withoutPriceRange(): self
    {
        return new self(
            storeId: $this->storeId,
            storeName: $this->storeName,
            categoryName: $this->categoryName,
            inStock: $this->inStock,
            query: $this->query,
            attributes: $this->attributes,
            requiredAttributes: $this->requiredAttributes,
            preferredAttributes: $this->preferredAttributes,
        );
    }

    /**
     * @param  list<AttributeConstraint|array{name?: string, value?: string, value_only?: bool}>  $rows
     * @return list<AttributeConstraint>
     */
    private function constraints(array $rows): array
    {
        $attributes = [];
        foreach ($rows as $row) {
            if ($row instanceof AttributeConstraint) {
                $attributes[] = $row;

                continue;
            }
            $attributes[] = new AttributeConstraint(
                name: (string) ($row['name'] ?? ''),
                value: (string) ($row['value'] ?? ''),
                valueOnly: (bool) ($row['value_only'] ?? false),
            );
        }

        return $attributes;
    }

    /**
     * @return list<AttributeConstraint>
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
            $attributes[] = new AttributeConstraint(
                name: $name,
                value: $value,
                valueOnly: $valueOnly,
            );
        }

        return $attributes;
    }

    /**
     * @param  list<AttributeConstraint>  $attributes
     * @return list<array{name: string, value: string, value_only?: true}>
     */
    private function constraintsToArray(array $attributes): array
    {
        return array_map(
            static fn (AttributeConstraint $row): array => $row->toArray(),
            $attributes,
        );
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
