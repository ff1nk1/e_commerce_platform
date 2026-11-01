<?php

declare(strict_types=1);

namespace App\Services\Indexing;

use App\DTO\Search\ProductDocument;
use App\Infrastructure\OpenSearch\ProductDocumentWriter;

final class ProductIndexer
{
    public function __construct(
        private readonly ProductDocumentWriter $products,
    ) {}

    public function upsert(ProductDocument $document, bool $preserveStock = true): void
    {
        $this->products->indexProduct($document, $preserveStock);
    }

    public function delete(int $id): void
    {
        $this->products->deleteProduct($id);
    }

    public function updateStock(int $productId, int $quantity): void
    {
        $this->products->updateProductStock($productId, $quantity);
    }
}
