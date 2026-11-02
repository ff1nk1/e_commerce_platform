<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTO\Catalog\AttributeConstraint;
use App\Models\ProductCatalog;
use Shop\Messaging\CatalogPayloadNormalizer;

final class UpsertProductService
{
    public function __construct(
        private readonly ProductEmbedder $embedder,
        private readonly AttributeVocabulary $attributeVocabulary = new AttributeVocabulary,
        private readonly CatalogPayloadNormalizer $payloads = new CatalogPayloadNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Kafka product data payload
     */
    public function handle(array $data, bool $reembed = true): ProductCatalog
    {
        $data = $this->payloads->normalize($data);
        $productId = (int) ($data['id'] ?? $data['product_id'] ?? 0);
        throw_if($productId <= 0, \InvalidArgumentException::class, 'product event missing id');

        $resolvedStoreId = (int) ($data['store_id'] ?? data_get($data, 'store.id', 0));
        $rawStoreName = data_get($data, 'store.name', $data['store_name'] ?? null);
        $resolvedStoreName = $rawStoreName !== null ? (string) $rawStoreName : null;
        $rawCategoryId = $data['category_id'] ?? data_get($data, 'category.id');
        $resolvedCategoryId = $rawCategoryId !== null ? (int) $rawCategoryId : null;
        $rawCategoryName = data_get($data, 'category.name', $data['category_name'] ?? null);
        $resolvedCategoryName = $rawCategoryName !== null ? (string) $rawCategoryName : null;

        $attributes = $data['attributes'] ?? [];
        if (! is_array($attributes)) {
            $attributes = [];
        }
        $normalizedAttributes = [];
        foreach ($attributes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $normalizedAttributes[] = new AttributeConstraint(name: $name, value: $value);
        }

        $isPublished = array_key_exists('is_published', $data)
            ? (bool) $data['is_published']
            : true;

        // Stock lives in stock.changed events. Preserve existing in_stock on product
        // upserts unless the payload explicitly carries stock fields (avoids OOS race).
        $inStock = $this->resolveInStock($productId, $data);

        $normalizedName = (string) ($data['name'] ?? '');
        $normalizedDescription = isset($data['description']) ? (string) $data['description'] : null;
        $normalizedImageUrl = isset($data['image_url']) ? (string) $data['image_url'] : null;
        $normalizedPrice = isset($data['price']) ? (float) $data['price'] : 0;

        /** @var ProductCatalog $product */
        $product = ProductCatalog::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'store_id' => $resolvedStoreId,
                'store_name' => $resolvedStoreName,
                'name' => $normalizedName,
                'description' => $normalizedDescription,
                'category_id' => $resolvedCategoryId,
                'category_name' => $resolvedCategoryName,
                'image_url' => $normalizedImageUrl,
                'price' => $normalizedPrice,
                'attributes' => array_map(
                    static fn (AttributeConstraint $attribute): array => $attribute->toArray(),
                    $normalizedAttributes,
                ),
                'is_published' => $isPublished,
                'in_stock' => $inStock,
            ],
        );

        if ($reembed) {
            $this->embedder->embed($product);
        }

        $this->attributeVocabulary->forgetAll();

        return $product->fresh() ?? $product;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveInStock(int $productId, array $data): bool
    {
        if (array_key_exists('in_stock', $data)) {
            return (bool) $data['in_stock'];
        }

        if (array_key_exists('quantity', $data)) {
            return ((int) $data['quantity']) > 0;
        }

        $existing = ProductCatalog::query()->where('product_id', $productId)->value('in_stock');
        if ($existing !== null) {
            return (bool) $existing;
        }

        return true;
    }
}
