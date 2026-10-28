<?php

declare(strict_types=1);

namespace App\Services\Catalog\Contracts;

use App\Services\Catalog\DTOs\CatalogFetchResult;

interface CatalogProductClient
{
    /**
     * Fetch product payloads keyed by catalog product id.
     * Distinguishes explicit 404s from transport/server failures.
     *
     * @param  list<int>  $productIds
     */
    public function fetchByIds(array $productIds): CatalogFetchResult;
}
