<?php

namespace App\Services\Catalog\Contracts;

interface CatalogProductClient
{
    /**
     * Fetch product payloads keyed by catalog product id.
     * Missing/failed ids are omitted from the result.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchByIds(array $productIds): array;
}
