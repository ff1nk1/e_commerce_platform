<?php

declare(strict_types=1);

namespace App\Services\Catalog\DTOs;

final readonly class SearchResult
{
    /**
     * @param  list<SearchHit>  $hits
     */
    public function __construct(
        public array $hits,
        public bool $preferredRelaxed = false,
    ) {}
}
