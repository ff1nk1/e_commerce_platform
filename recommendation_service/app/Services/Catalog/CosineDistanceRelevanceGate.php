<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\Catalog\Contracts\RelevanceGate;
use App\Services\Catalog\DTOs\SearchHit;

final class CosineDistanceRelevanceGate implements RelevanceGate
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
