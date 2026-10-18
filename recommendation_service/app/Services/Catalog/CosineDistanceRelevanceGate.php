<?php

namespace App\Services\Catalog;

use App\Services\Catalog\Contracts\RelevanceGate;
use App\Services\Catalog\DTOs\SearchHit;

class CosineDistanceRelevanceGate implements RelevanceGate
{
    public function __construct(
        private readonly float $maxCosineDistance,
    ) {}

    public function accept(array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        return array_values(array_filter(
            $hits,
            fn (SearchHit $hit): bool => $hit->distance <= $this->maxCosineDistance
        ));
    }
}
