<?php

declare(strict_types=1);

namespace App\Events;

use App\DTO\Search\ProductSearchQuery;
use Illuminate\Foundation\Events\Dispatchable;

final class SearchPerformed
{
    use Dispatchable;

    public function __construct(
        public readonly ProductSearchQuery $query,
        public readonly int $resultsCount,
        public readonly bool $skipAnalytics = false,
    ) {}
}
