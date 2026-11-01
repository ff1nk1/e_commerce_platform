<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Events\ProductDeleted;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class DeleteProductService
{
    public function __construct(
        private ProductCache $cache,
    ) {}

    public function handle(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product->loadMissing(['store', 'category', 'attributes']);
            $productData = clone $product;
            $productData->setRelation('attributes', $product->attributes);
            $product->delete();
            event(new ProductDeleted($productData));
        });

        $this->cache->clear();
    }
}
