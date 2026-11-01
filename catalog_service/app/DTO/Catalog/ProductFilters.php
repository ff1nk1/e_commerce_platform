<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

use App\Enums\CatalogAvailabilityFilter;
use App\Enums\ProductSort;

final readonly class ProductFilters
{
    public function __construct(
        public ?string $search = null,
        public ?float $minPrice = null,
        public ?float $maxPrice = null,
        public ?CatalogAvailabilityFilter $status = null,
        public ProductSort $sort = ProductSort::New,
        public int $page = 1,
        public int $perPage = 20,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = $data['status'] ?? null;
        if (is_string($status)) {
            $status = CatalogAvailabilityFilter::tryFrom($status);
        } elseif (! $status instanceof CatalogAvailabilityFilter) {
            $status = null;
        }

        $sort = $data['sort'] ?? ProductSort::New;
        if (! $sort instanceof ProductSort) {
            $sort = is_string($sort) ? (ProductSort::tryFrom($sort) ?? ProductSort::New) : ProductSort::New;
        }

        return new self(
            search: isset($data['search']) && is_string($data['search']) && $data['search'] !== '' ? $data['search'] : null,
            minPrice: isset($data['min_price']) && is_numeric($data['min_price']) ? (float) $data['min_price'] : null,
            maxPrice: isset($data['max_price']) && is_numeric($data['max_price']) ? (float) $data['max_price'] : null,
            status: $status,
            sort: $sort,
            page: isset($data['page']) && is_numeric($data['page']) ? max(1, (int) $data['page']) : 1,
            perPage: isset($data['per_page']) && is_numeric($data['per_page']) ? max(1, (int) $data['per_page']) : 20,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'search' => $this->search,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'status' => $this->status?->value,
            'sort' => $this->sort->value,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
