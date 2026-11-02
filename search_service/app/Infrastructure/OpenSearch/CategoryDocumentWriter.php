<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenSearch;

use App\DTO\Search\CategoryDocument;
use OpenSearch\Client;

final class CategoryDocumentWriter
{
    private const MAX_NAME_LENGTH = 255;

    private const MAX_SLUG_LENGTH = 255;

    public function __construct(
        private readonly Client $client,
        private readonly OpenSearchIndexManager $indices,
        private readonly DocumentStore $documents,
    ) {}

    public function indexCategory(CategoryDocument $document): void
    {
        $this->indices->ensureIndices();

        $id = $document->id;
        if ($id <= 0) {
            return;
        }

        $name = mb_substr($document->name, 0, self::MAX_NAME_LENGTH);
        $slug = mb_substr($document->slug, 0, self::MAX_SLUG_LENGTH);

        $this->client->index([
            'index' => $this->indices->categoriesIndex(),
            'id' => (string) $id,
            'body' => [
                'id' => $id,
                'parent_id' => $document->parentId,
                'name' => $name,
                'slug' => $slug,
            ],
            'refresh' => $this->refreshOnWrite(),
        ]);
    }

    public function deleteCategory(int $id): void
    {
        $this->indices->ensureIndices();
        $this->documents->delete($this->indices->categoriesIndex(), (string) $id, $this->refreshOnWrite());
    }

    public function syncCategoryNameOnProducts(int $categoryId, string $categoryName): void
    {
        $this->indices->ensureIndices();

        $categoryName = mb_substr($categoryName, 0, self::MAX_NAME_LENGTH);

        $this->client->updateByQuery([
            'index' => $this->indices->productsIndex(),
            'refresh' => $this->refreshOnWrite(),
            'conflicts' => 'proceed',
            'body' => [
                'query' => [
                    'term' => ['category_id' => $categoryId],
                ],
                'script' => [
                    'source' => 'ctx._source.category_name = params.category_name',
                    'lang' => 'painless',
                    'params' => [
                        'category_name' => $categoryName,
                    ],
                ],
            ],
        ]);
    }

    private function refreshOnWrite(): bool
    {
        return $this->indices->refreshOnWrite();
    }
}
