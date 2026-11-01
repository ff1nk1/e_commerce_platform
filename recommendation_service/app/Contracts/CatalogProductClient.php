<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Catalog\CatalogFetchResult;

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
