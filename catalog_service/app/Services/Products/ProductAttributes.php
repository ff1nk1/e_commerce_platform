<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTO\Catalog\ProductAttributeData;
use App\Models\Product;

final class ProductAttributes
{
    /**
     * @param  list<ProductAttributeData>  $attributes
     */
    public function sync(Product $product, array $attributes): void
    {
        $product->attributes()->delete();

        if ($attributes === []) {
            return;
        }

        $product->attributes()->createMany(array_map(
            static fn (ProductAttributeData $attribute): array => $attribute->toArray(),
            $attributes,
        ));
    }
}
