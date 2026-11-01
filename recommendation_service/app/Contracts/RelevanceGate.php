<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Catalog\SearchHit;

interface RelevanceGate
{
    /**
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    public function accept(array $hits): array;
}
