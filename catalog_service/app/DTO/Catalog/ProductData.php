<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

final readonly class ProductData
{
    /**
     * @param  list<ProductAttributeData>|null  $attributes  null when the key is absent (do not sync)
     */
    public function __construct(
        public ?int $storeId,
        public bool $hasStoreId,
        public ?int $categoryId,
        public bool $hasCategoryId,
        public ?string $name,
        public bool $hasName,
        public ?string $description,
        public bool $hasDescription,
        public ?string $imageUrl,
        public bool $hasImageUrl,
        public int|float|string|null $price,
        public bool $hasPrice,
        public ?array $attributes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            storeId: self::nullableInt($data, 'store_id'),
            hasStoreId: array_key_exists('store_id', $data),
            categoryId: self::nullableInt($data, 'category_id'),
            hasCategoryId: array_key_exists('category_id', $data),
            name: self::nullableString($data, 'name'),
            hasName: array_key_exists('name', $data),
            description: self::nullableString($data, 'description'),
            hasDescription: array_key_exists('description', $data),
            imageUrl: self::nullableString($data, 'image_url'),
            hasImageUrl: array_key_exists('image_url', $data),
            price: self::price($data),
            hasPrice: array_key_exists('price', $data),
            attributes: self::attributes($data),
        );
    }

    /**
     * Mass-assignment payload. Attributes are synced separately.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->hasStoreId) {
            $payload['store_id'] = $this->storeId;
        }
        if ($this->hasCategoryId) {
            $payload['category_id'] = $this->categoryId;
        }
        if ($this->hasName) {
            $payload['name'] = $this->name;
        }
        if ($this->hasDescription) {
            $payload['description'] = $this->description;
        }
        if ($this->hasImageUrl) {
            $payload['image_url'] = $this->imageUrl;
        }
        if ($this->hasPrice) {
            $payload['price'] = $this->price;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableInt(array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return (int) $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return (string) $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function price(array $data): int|float|string|null
    {
        if (! array_key_exists('price', $data) || $data['price'] === null) {
            return null;
        }

        $price = $data['price'];
        if (is_int($price) || is_float($price) || is_string($price)) {
            return $price;
        }

        return (string) $price;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<ProductAttributeData>|null
     */
    private static function attributes(array $data): ?array
    {
        if (! array_key_exists('attributes', $data)) {
            return null;
        }

        $raw = $data['attributes'];
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $attribute = ProductAttributeData::fromArray($row);
            if ($attribute->name === '' || $attribute->value === '') {
                continue;
            }

            $normalized[] = $attribute;
        }

        return $normalized;
    }
}
