<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Contracts\RelevanceGate;
use App\DTO\Catalog\SearchHit;

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
