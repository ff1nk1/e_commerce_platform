<?php

declare(strict_types=1);

namespace App\Indexing;

use App\Indexing\Concerns\DeletesOpenSearchDocuments;
use OpenSearch\Client;

final class CategoryDocumentWriter
{
    use DeletesOpenSearchDocuments;

    private const MAX_NAME_LENGTH = 255;

    private const MAX_SLUG_LENGTH = 255;

    public function __construct(
        private readonly Client $client,
        private readonly OpenSearchIndexManager $indices,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function indexCategory(array $data): void
    {
        $this->indices->ensureIndices();

        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $name = mb_substr((string) ($data['name'] ?? ''), 0, self::MAX_NAME_LENGTH);
        $slug = mb_substr((string) ($data['slug'] ?? ''), 0, self::MAX_SLUG_LENGTH);

        $this->client->index([
            'index' => $this->indices->categoriesIndex(),
            'id' => (string) $id,
            'body' => [
                'id' => $id,
                'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : null,
                'name' => $name,
                'slug' => $slug,
            ],
            'refresh' => $this->refreshOnWrite(),
        ]);
    }

    public function deleteCategory(int $id): void
    {
        $this->indices->ensureIndices();
        $this->deleteDocument($this->indices->categoriesIndex(), (string) $id);
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

    protected function openSearchClient(): Client
    {
        return $this->client;
    }

    protected function refreshOnWrite(): bool
    {
        return $this->indices->refreshOnWrite();
    }
}
