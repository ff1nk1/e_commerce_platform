<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

use App\Models\ProductCatalog;

final readonly class SearchHit
{
    public function __construct(
        public ProductCatalog $product,
        public float $distance,
    ) {}
}
