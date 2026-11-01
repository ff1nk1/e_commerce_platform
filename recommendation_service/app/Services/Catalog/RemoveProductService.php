<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductCatalog;

final class RemoveProductService
{
    public function __construct(
        private readonly AttributeVocabulary $attributeVocabulary = new AttributeVocabulary,
    ) {}

    public function handle(int $productId): void
    {
        ProductCatalog::query()->where('product_id', $productId)->delete();
        $this->attributeVocabulary->forgetAll();
    }
}
