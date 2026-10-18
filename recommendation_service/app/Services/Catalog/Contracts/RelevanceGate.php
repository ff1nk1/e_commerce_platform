<?php

namespace App\Services\Catalog\Contracts;

use App\Services\Catalog\DTOs\SearchHit;

interface RelevanceGate
{
    /**
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    public function accept(array $hits): array;
}
