<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\SearchIndexService;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Request;
use OpenSearch\Client;
use Throwable;

trait UsesOpenSearch
{
    protected function openSearchAvailable(): bool
    {
        try {
            /** @var Client $client */
            $client = $this->app->make(Client::class);

            return (bool) $client->ping();
        } catch (Throwable) {
            return false;
        }
    }

    protected function skipUnlessOpenSearchAvailable(): void
    {
        if (! $this->openSearchAvailable()) {
            $this->markTestSkipped(
                'OpenSearch is not available (set OPENSEARCH_HOSTS, e.g. http://search_opensearch:9200 or http://localhost:9200).'
            );
        }
    }

    protected function recreateSearchIndices(): void
    {
        $productsIndex = Env::get('OPENSEARCH_PRODUCTS_INDEX', Request::server('OPENSEARCH_PRODUCTS_INDEX')
        ?? getenv('OPENSEARCH_PRODUCTS_INDEX'))
            ?: 'products_test';
        $categoriesIndex = Env::get('OPENSEARCH_CATEGORIES_INDEX', Request::server('OPENSEARCH_CATEGORIES_INDEX')
        ?? getenv('OPENSEARCH_CATEGORIES_INDEX'))
            ?: 'categories_test';

        config([
            'opensearch.indices.products' => is_string($productsIndex) ? $productsIndex : 'products_test',
            'opensearch.indices.categories' => is_string($categoriesIndex) ? $categoriesIndex : 'categories_test',
        ]);

        // Client is a singleton — rebuild if hosts changed in tests.
        $this->app->forgetInstance(Client::class);

        resolve(SearchIndexService::class)->recreateIndices();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function productDocument(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => "Product {$id}",
            'description' => "Description for product {$id}",
            'store_id' => 1,
            'store_name' => 'Main Store',
            'category_id' => 1,
            'category_name' => 'Gadgets',
            'price' => 100.0,
            'quantity' => 5,
            'in_stock' => true,
            'image_url' => null,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Black'],
            ],
        ], $overrides);
    }
}
