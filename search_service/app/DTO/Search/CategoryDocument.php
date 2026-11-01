<?php

declare(strict_types=1);

namespace App\DTO\Search;

final readonly class CategoryDocument
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $name,
        public string $slug,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $parentId = $data['parent_id'] ?? null;

        return new self(
            id: (int) ($data['id'] ?? 0),
            parentId: $parentId !== null ? (int) $parentId : null,
            name: (string) ($data['name'] ?? ''),
            slug: (string) ($data['slug'] ?? ''),
        );
    }
}
