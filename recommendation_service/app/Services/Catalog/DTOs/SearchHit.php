<?php

namespace App\Services\Catalog\DTOs;

use App\Models\ProductCatalog;

readonly class SearchHit
{
    public function __construct(
        public ProductCatalog $product,
        public float $distance,
    ) {}
}
