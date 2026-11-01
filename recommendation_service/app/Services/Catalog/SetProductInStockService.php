<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductCatalog;

final class SetProductInStockService
{
    public function handle(int $productId, bool $inStock): void
    {
        ProductCatalog::query()
            ->where('product_id', $productId)
            ->update(['in_stock' => $inStock]);
    }
}
