<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTO\Catalog\SearchHit;
use App\DTO\Chat\SearchFilters;

final class SearchCatalogProductsService
{
    public function __construct(
        private readonly SearchCatalogProductsDetailedService $detailed,
    ) {}

    /**
     * @param  list<float>|null  $precomputedVector
     * @return list<SearchHit>
     */
    public function handle(SearchFilters $filters, int $limit = 5, ?array $precomputedVector = null): array
    {
        return $this->detailed->handle($filters, $limit, $precomputedVector)->hits;
    }
}
