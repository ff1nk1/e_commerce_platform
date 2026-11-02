<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

use App\DTO\Chat\SearchFilters;

/**
 * Result of one progressive filter-relaxation step.
 */
final readonly class RelaxationStep
{
    public function __construct(
        public SearchFilters $filters,
        public string $relaxed,
    ) {}
}
