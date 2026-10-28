<?php

declare(strict_types=1);

namespace App\Indexing;

use OpenSearch\Client;
use Throwable;

final class OpenSearchIndexManager
{
    private bool $indicesEnsured = false;

    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * Hot path: create missing concrete indices only. Never putMapping / never touch aliases.
     */
    public function ensureIndices(): void
    {
        if ($this->indicesEnsured) {
            return;
        }

        $products = $this->productsIndex();
        if (! $this->nameExists($products)) {
            $this->createProductsIndex($products);
        }

        $categories = $this->categoriesIndex();
        if (! $this->nameExists($categories)) {
            $this->createCategoriesIndex($categories);
        }

        $this->indicesEnsured = true;
    }

    /**
     * Drop products/categories (including alias targets) and recreate with full settings + mappings.
     */
    public function recreateIndices(): void
    {
        $this->deleteIndexOrAlias($this->productsIndex());
        $this->deleteIndexOrAlias($this->categoriesIndex());

        foreach (['products_new', 'products_v1', 'categories_new'] as $legacy) {
            if ($this->nameExists($legacy)) {
                $this->deleteIndexOrAlias($legacy);
            }
        }

        $this->createProductsIndex($this->productsIndex());
        $this->createCategoriesIndex($this->categoriesIndex());

        $this->indicesEnsured = true;
    }

    public function productsIndex(): string
    {
        return (string) config('opensearch.indices.products', 'products');
    }

    public function categoriesIndex(): string
    {
        return (string) config('opensearch.indices.categories', 'categories');
    }

    public function refreshOnWrite(): bool
    {
        return (bool) config('opensearch.refresh_on_write', false);
    }

    private function nameExists(string $name): bool
    {
        try {
            return $this->client->indices()->exists(['index' => $name]);
        } catch (Throwable) {
            return false;
        }
    }

    private function deleteIndexOrAlias(string $name): void
    {
        if (! $this->nameExists($name)) {
            return;
        }

        try {
            $aliasInfo = $this->client->indices()->getAlias(['name' => $name]);
            if ($aliasInfo !== []) {
                foreach (array_keys($aliasInfo) as $concreteIndex) {
                    $this->client->indices()->delete(['index' => (string) $concreteIndex]);
                }

                return;
            }
        } catch (Throwable) {
            // not an alias — fall through to delete as index
        }

        try {
            $this->client->indices()->delete(['index' => $name]);
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            throw_if(! str_contains($message, 'not_found') && ! str_contains($message, '404'), $e);
        }
    }

    private function createProductsIndex(string $name): void
    {
        $this->client->indices()->create([
            'index' => $name,
            'body' => [
                'settings' => $this->productIndexSettings(),
                'mappings' => [
                    'properties' => $this->productMappings(),
                ],
            ],
        ]);
    }

    private function createCategoriesIndex(string $name): void
    {
        $this->client->indices()->create([
            'index' => $name,
            'body' => [
                'mappings' => [
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'parent_id' => ['type' => 'integer'],
                        'name' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                        'slug' => ['type' => 'keyword'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productIndexSettings(): array
    {
        $synonyms = config('opensearch.synonyms', []);
        if (! is_array($synonyms) || $synonyms === []) {
            $synonyms = [
                'ноутбук, ноутбуки, laptop, notebook',
                'телефон, смартфон, phone, smartphone',
            ];
        }

        return [
            'analysis' => [
                'filter' => [
                    'product_synonyms' => [
                        'type' => 'synonym_graph',
                        'lenient' => true,
                        'synonyms' => array_values($synonyms),
                    ],
                ],
                'analyzer' => [
                    'product_index' => [
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase'],
                    ],
                    'product_search' => [
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'product_synonyms'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productMappings(): array
    {
        return [
            'id' => ['type' => 'integer'],
            'store_id' => ['type' => 'integer'],
            'category_id' => ['type' => 'integer'],
            'name' => [
                'type' => 'text',
                'analyzer' => 'product_index',
                'search_analyzer' => 'product_search',
                'fields' => [
                    'keyword' => ['type' => 'keyword'],
                    'autocomplete' => ['type' => 'search_as_you_type'],
                ],
            ],
            'description' => [
                'type' => 'text',
                'analyzer' => 'product_index',
                'search_analyzer' => 'product_search',
            ],
            'image_url' => ['type' => 'keyword', 'index' => false],
            'price' => ['type' => 'float'],
            'category_name' => [
                'type' => 'text',
                'fields' => ['keyword' => ['type' => 'keyword']],
            ],
            'store_name' => [
                'type' => 'text',
                'fields' => ['keyword' => ['type' => 'keyword']],
            ],
            'quantity' => ['type' => 'integer'],
            'in_stock' => ['type' => 'boolean'],
            'attributes' => [
                'type' => 'nested',
                'properties' => [
                    'name' => ['type' => 'keyword'],
                    'value' => ['type' => 'keyword'],
                ],
            ],
        ];
    }
}
