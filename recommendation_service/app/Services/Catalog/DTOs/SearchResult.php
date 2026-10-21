<?php

namespace App\Services\Catalog\DTOs;

readonly class SearchResult
{
    /**
     * @param  list<SearchHit>  $hits
     */
    public function __construct(
        public array $hits,
        public bool $preferredRelaxed = false,
    ) {}
}
