<?php

declare(strict_types=1);

namespace App\DTO\Search;

use App\Enums\ProductSort;

final readonly class ProductSearchQuery
{
    /**
     * @param  list<int>  $categoryIds
     * @param  array<string, list<string>>  $attributes
     */
    public function __construct(
        public ?string $q,
        public array $categoryIds,
        public ?float $minPrice,
        public ?float $maxPrice,
        public ?bool $inStock,
        public array $attributes,
        public int $page,
        public int $perPage,
        public ProductSort $sort,
    ) {}

    /**
     * @param  array{
     *     q?: string|null,
     *     category_ids?: list<int>,
     *     min_price?: float|null,
     *     max_price?: float|null,
     *     in_stock?: bool|null,
     *     attributes?: array<string, list<string>>,
     *     page?: int,
     *     per_page?: int,
     *     sort?: string|ProductSort
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $sort = $data['sort'] ?? ProductSort::New;
        if (! $sort instanceof ProductSort) {
            $sort = ProductSort::tryFrom($sort) ?? ProductSort::New;
        }

        return new self(
            q: $data['q'] ?? null,
            categoryIds: $data['category_ids'] ?? [],
            minPrice: $data['min_price'] ?? null,
            maxPrice: $data['max_price'] ?? null,
            inStock: $data['in_stock'] ?? null,
            attributes: $data['attributes'] ?? [],
            page: $data['page'] ?? 1,
            perPage: $data['per_page'] ?? 20,
            sort: $sort,
        );
    }

    /**
     * Shape previously passed through search() and hashed for search.performed.
     *
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
    public function toArray(): array
    {
        return [
            'q' => $this->q,
            'category_ids' => $this->categoryIds,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'in_stock' => $this->inStock,
            'attributes' => $this->attributes,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'sort' => $this->sort->value,
        ];
    }
}
