<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTO\Catalog\ProductData;
use App\Events\ProductUpdated;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class UpdateProductService
{
    public function __construct(
        private ProductCache $cache,
        private ProductAttributes $attributes,
    ) {}

    public function handle(Product $product, ProductData $data): Product
    {
        $product = DB::transaction(function () use ($product, $data): Product {
            $product->update($data->toArray());
            if ($data->attributes !== null) {
                $this->attributes->sync($product, $data->attributes);
            }
            $product = $product->load(['store', 'category', 'attributes']);
            event(new ProductUpdated($product));

            return $product;
        });

        $this->cache->clear();

        return $product;
    }
}
