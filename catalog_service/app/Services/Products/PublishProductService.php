<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\CatalogEventType;
use App\Events\ProductPublishStateChanged;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class PublishProductService
{
    public function __construct(
        private ProductCache $cache,
    ) {}

    public function handle(Product $product): Product
    {
        $product = DB::transaction(function () use ($product): Product {
            $product->is_published = ! (bool) $product->is_published;
            $product->save();
            $product = $product->load(['store', 'category', 'attributes']);
            $publishEventType = $product->is_published
                ? CatalogEventType::ProductPublished
                : CatalogEventType::ProductUnpublished;
            event(new ProductPublishStateChanged($product, $publishEventType));

            return $product;
        });

        $this->cache->clear();

        return $product;
    }
}
