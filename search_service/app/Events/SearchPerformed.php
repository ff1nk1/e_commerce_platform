<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SearchPerformed
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly array $params,
        public readonly int $resultsCount,
        public readonly bool $skipAnalytics = false,
    ) {}
}
