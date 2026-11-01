<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductCatalog;
use Illuminate\Support\Facades\Log;

final class SetProductPublishedService
{
    public function __construct(
        private readonly ProductEmbedder $embedder,
    ) {}

    public function handle(int $productId, bool $published): void
    {
        $product = ProductCatalog::query()->where('product_id', $productId)->first();
        if ($product === null) {
            Log::warning('ProductIndexer: setPublished for missing product', ['product_id' => $productId]);

            return;
        }

        $product->is_published = $published;
        $product->save();

        if ($published) {
            $this->embedder->embed($product);
        }
    }
}
