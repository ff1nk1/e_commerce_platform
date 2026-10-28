<?php

declare(strict_types=1);

namespace App\Services;

use App\Indexing\CategoryDocumentWriter;
use App\Indexing\OpenSearchIndexManager;
use App\Indexing\ProductDocumentWriter;

/**
 * Facade over index lifecycle and document writers (stable API for handlers / tests).
 */
final class SearchIndexService
{
    public function __construct(
        private readonly OpenSearchIndexManager $indices,
        private readonly ProductDocumentWriter $products,
        private readonly CategoryDocumentWriter $categories,
    ) {}

    public function ensureIndices(): void
    {
        $this->indices->ensureIndices();
    }

    public function recreateIndices(): void
    {
        $this->indices->recreateIndices();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function indexProduct(array $data, bool $preserveStock = true): void
    {
        $this->products->indexProduct($data, $preserveStock);
    }

    public function deleteProduct(int $id): void
    {
        $this->products->deleteProduct($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function indexCategory(array $data): void
    {
        $this->categories->indexCategory($data);
    }

    public function deleteCategory(int $id): void
    {
        $this->categories->deleteCategory($id);
    }

    public function syncCategoryNameOnProducts(int $categoryId, string $categoryName): void
    {
        $this->categories->syncCategoryNameOnProducts($categoryId, $categoryName);
    }

    public function updateProductStock(int $productId, int $quantity): void
    {
        $this->products->updateProductStock($productId, $quantity);
    }
}
