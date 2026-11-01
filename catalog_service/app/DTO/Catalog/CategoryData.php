<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

final readonly class CategoryData
{
    public function __construct(
        public ?string $name,
        public bool $hasName,
        public ?string $slug,
        public bool $hasSlug,
        public int|string|null $parentId,
        public bool $hasParentId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: self::nullableString($data, 'name'),
            hasName: array_key_exists('name', $data),
            slug: self::nullableString($data, 'slug'),
            hasSlug: array_key_exists('slug', $data),
            parentId: self::parentId($data),
            hasParentId: array_key_exists('parent_id', $data),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->hasName) {
            $payload['name'] = $this->name;
        }
        if ($this->hasSlug) {
            $payload['slug'] = $this->slug;
        }
        if ($this->hasParentId) {
            $payload['parent_id'] = $this->parentId;
        }

        return $payload;
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
    private static function parentId(array $data): int|string|null
    {
        if (! array_key_exists('parent_id', $data) || $data['parent_id'] === null) {
            return null;
        }

        $parentId = $data['parent_id'];
        if (is_int($parentId) || is_string($parentId)) {
            return $parentId;
        }

        return (string) $parentId;
    }
}
