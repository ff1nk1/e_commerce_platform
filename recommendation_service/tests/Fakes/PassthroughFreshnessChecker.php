<?php

namespace Tests\Fakes;

use App\Services\Catalog\Contracts\ProductFreshnessChecker;

class PassthroughFreshnessChecker implements ProductFreshnessChecker
{
    public function keepFresh(array $hits): array
    {
        return $hits;
    }
}
