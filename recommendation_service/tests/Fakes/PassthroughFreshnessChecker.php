<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Catalog\Contracts\ProductFreshnessChecker;

final class PassthroughFreshnessChecker implements ProductFreshnessChecker
{
    public function keepFresh(array $hits): array
    {
        return $hits;
    }
}
