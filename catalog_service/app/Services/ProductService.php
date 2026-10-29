<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\ProductCreated;
use App\Events\ProductDeleted;
use App\Events\ProductPublishStateChanged;
use App\Events\ProductUpdated;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ProductService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createProduct(array $data): Product
    {
        [$attributes, $data] = $this->extractAttributes($data);

        $product = DB::transaction(function () use ($data, $attributes): Product {
            $product = Product::query()->create($data);
            $this->syncAttributes($product, $attributes ?? []);
            $product = $product->load(['store', 'category', 'attributes']);
            event(new ProductCreated($product));

            return $product;
        });

        $this->clearProductCache();

        return $product;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProduct(Product $product, array $data): Product
    {
        [$attributes, $data] = $this->extractAttributes($data);

        $product = DB::transaction(function () use ($product, $data, $attributes): Product {
            $product->update($data);
            if ($attributes !== null) {
                $this->syncAttributes($product, $attributes);
            }
            $product = $product->load(['store', 'category', 'attributes']);
            event(new ProductUpdated($product));

            return $product;
        });

        $this->clearProductCache();

        return $product;
    }

    public function deleteProduct(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product->loadMissing(['store', 'category', 'attributes']);
            $productData = clone $product;
            $productData->setRelation('attributes', $product->attributes);
            $product->delete();
            event(new ProductDeleted($productData));
        });

        $this->clearProductCache();
    }

    public function togglePublished(Product $product): Product
    {
        $product = DB::transaction(function () use ($product): Product {
            $product->is_published = ! (bool) $product->is_published;
            $product->save();
            $product = $product->load(['store', 'category', 'attributes']);
            $publishEventType = $product->is_published ? 'product.published' : 'product.unpublished';
            event(new ProductPublishStateChanged($product, $publishEventType));

            return $product;
        });

        $this->clearProductCache();

        return $product;
    }

    public function clearProductCache(): void
    {
        Cache::tags(['products'])->flush();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: list<array{name: string, value: string}>|null, 1: array<string, mixed>}
     *                                                                                          null attributes = key absent (do not sync)
     */
    private function extractAttributes(array $data): array
    {
        if (! array_key_exists('attributes', $data)) {
            return [null, $data];
        }

        $raw = $data['attributes'];
        unset($data['attributes']);
        if (! is_array($raw)) {
            return [[], $data];
        }

        $normalized = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'value' => $value,
            ];
        }

        return [$normalized, $data];
    }

    /**
     * @param  list<array{name: string, value: string}>  $attributes
     */
    private function syncAttributes(Product $product, array $attributes): void
    {
        $product->attributes()->delete();

        if ($attributes === []) {
            return;
        }

        $product->attributes()->createMany($attributes);
    }
}
