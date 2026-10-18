<?php

namespace App\Indexing;

use App\Indexing\Concerns\DeletesOpenSearchDocuments;
use Illuminate\Support\Facades\Log;
use OpenSearch\Client;
use Throwable;

class ProductDocumentWriter
{
    use DeletesOpenSearchDocuments;

    private const MAX_NAME_LENGTH = 500;

    private const MAX_DESCRIPTION_LENGTH = 5000;

    private const MAX_IMAGE_URL_LENGTH = 2048;

    private const MAX_ATTRIBUTES = 50;

    private const MAX_ATTR_FIELD_LENGTH = 64;

    public function __construct(
        private readonly Client $client,
        private readonly OpenSearchIndexManager $indices,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function indexProduct(array $data, bool $preserveStock = true): void
    {
        $this->indices->ensureIndices();

        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $document = $this->mapCatalogFields($data);

        if ($preserveStock && $this->productExists($id)) {
            // Partial update: never overwrite quantity/in_stock (avoids race with stock.changed).
            $this->client->update([
                'index' => $this->indices->productsIndex(),
                'id' => (string) $id,
                'body' => ['doc' => $document],
                'refresh' => $this->refreshOnWrite(),
            ]);

            return;
        }

        $quantity = (int) ($data['quantity'] ?? 0);
        $document['quantity'] = $quantity;
        $document['in_stock'] = (bool) ($data['in_stock'] ?? ($quantity > 0));

        $this->client->index([
            'index' => $this->indices->productsIndex(),
            'id' => (string) $id,
            'body' => $document,
            'refresh' => $this->refreshOnWrite(),
        ]);
    }

    public function deleteProduct(int $id): void
    {
        $this->indices->ensureIndices();
        $this->deleteDocument($this->indices->productsIndex(), (string) $id);
    }

    public function updateProductStock(int $productId, int $quantity): void
    {
        $this->indices->ensureIndices();

        if (! $this->productExists($productId)) {
            Log::warning('OpenSearch: stock.changed for missing product document', [
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);

            return;
        }

        $this->client->update([
            'index' => $this->indices->productsIndex(),
            'id' => (string) $productId,
            'refresh' => $this->refreshOnWrite(),
            'body' => [
                'doc' => [
                    'quantity' => $quantity,
                    'in_stock' => $quantity > 0,
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapCatalogFields(array $data): array
    {
        $name = mb_substr((string) ($data['name'] ?? ''), 0, self::MAX_NAME_LENGTH);
        $description = $data['description'] ?? null;
        if (is_string($description)) {
            $description = mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH);
        }

        $imageUrl = $data['image_url'] ?? null;
        if (is_string($imageUrl)) {
            $imageUrl = mb_substr($imageUrl, 0, self::MAX_IMAGE_URL_LENGTH);
            if ($imageUrl !== '' && ! preg_match('#^https?://#i', $imageUrl)) {
                $imageUrl = null;
            }
        }

        return [
            'id' => (int) ($data['id'] ?? 0),
            'store_id' => isset($data['store_id']) ? (int) $data['store_id'] : null,
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : (
                isset($data['category']['id']) ? (int) $data['category']['id'] : null
            ),
            'name' => $name,
            'description' => $description,
            'image_url' => $imageUrl,
            'price' => isset($data['price']) ? (float) $data['price'] : null,
            'category_name' => $data['category']['name'] ?? ($data['category_name'] ?? null),
            'store_name' => $data['store']['name'] ?? ($data['store_name'] ?? null),
            'attributes' => $this->normalizeAttributes($data['attributes'] ?? []),
        ];
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function normalizeAttributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $normalized = [];
        foreach ($attributes as $row) {
            if (count($normalized) >= self::MAX_ATTRIBUTES) {
                break;
            }

            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }

            $normalized[] = [
                'name' => mb_substr($name, 0, self::MAX_ATTR_FIELD_LENGTH),
                'value' => mb_substr($value, 0, self::MAX_ATTR_FIELD_LENGTH),
            ];
        }

        return $normalized;
    }

    private function productExists(int $id): bool
    {
        try {
            return (bool) $this->client->exists([
                'index' => $this->indices->productsIndex(),
                'id' => (string) $id,
            ]);
        } catch (Throwable) {
            return false;
        }
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
