<?php

namespace App\Services\Catalog\Contracts;

use App\Services\Catalog\DTOs\SearchHit;

interface ProductFreshnessChecker
{
    /**
     * Keep only hits that are still valid in Catalog (or pass through on transport failure).
     *
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    public function keepFresh(array $hits): array;
}
