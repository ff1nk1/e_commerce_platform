<?php

declare(strict_types=1);

namespace App\DTO\Search;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ProductSearchResult implements Arrayable, JsonSerializable
{
    /**
     * @param  array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}  $products
     * @param  array<string, mixed>  $facets
     * @param  array{q: string|null, filters: array{category_ids: list<int>, min_price: float|null, max_price: float|null, in_stock: bool|null, attributes: array<string, list<string>>}, sort: string}  $applied
     */
    public function __construct(
        public array $products,
        public array $facets,
        public array $applied,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'products' => $this->products,
            'facets' => $this->facets,
            'applied' => $this->applied,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
