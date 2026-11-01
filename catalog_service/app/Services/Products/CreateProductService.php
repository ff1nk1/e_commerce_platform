<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTO\Catalog\ProductData;
use App\Events\ProductCreated;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class CreateProductService
{
    public function __construct(
        private ProductCache $cache,
        private ProductAttributes $attributes,
    ) {}

    public function handle(ProductData $data): Product
    {
        $product = DB::transaction(function () use ($data): Product {
            $product = Product::query()->create($data->toArray());
            $this->attributes->sync($product, $data->attributes ?? []);
            $product = $product->load(['store', 'category', 'attributes']);
            event(new ProductCreated($product));

            return $product;
        });

        $this->cache->clear();

        return $product;
    }
}
