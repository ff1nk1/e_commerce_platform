<?php

declare(strict_types=1);

namespace App\Services\Indexing;

use App\DTO\Search\CategoryDocument;
use App\Infrastructure\OpenSearch\CategoryDocumentWriter;

final class CategoryIndexer
{
    public function __construct(
        private readonly CategoryDocumentWriter $categories,
    ) {}

    public function upsert(CategoryDocument $document): void
    {
        $this->categories->indexCategory($document);
    }

    public function delete(int $id): void
    {
        $this->categories->deleteCategory($id);
    }

    public function syncNameOnProducts(int $categoryId, string $categoryName): void
    {
        $this->categories->syncCategoryNameOnProducts($categoryId, $categoryName);
    }
}
