<?php

namespace App\Services\Llm\DTOs;

readonly class SearchFilters
{
    /**
     * @param  list<array{name: string, value: string}>  $attributes  Soft preferences (re-rank)
     * @param  list<array{name: string, value: string}>  $requiredAttributes  Hard constraints (must match)
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
        ];
    }

    /**
     * @return list<array{name: string, value: string}>
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
            if ($name === '' || $value === '') {
                continue;
            }
            $attributes[] = ['name' => $name, 'value' => $value];
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
