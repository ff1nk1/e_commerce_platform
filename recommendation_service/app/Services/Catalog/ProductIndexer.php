<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductCatalog;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\Exceptions\LlmException;
use App\Support\PgVectorLiteral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\CatalogPayloadNormalizer;

final class ProductIndexer
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ProductTextBuilder $textBuilder,
        private readonly AttributeVocabulary $attributeVocabulary = new AttributeVocabulary,
        private readonly CatalogPayloadNormalizer $payloads = new CatalogPayloadNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Kafka product data payload
     */
    public function upsertFromEvent(array $data, bool $reembed = true): ProductCatalog
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
            $normalizedAttributes[] = ['name' => $name, 'value' => $value];
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
                'attributes' => $normalizedAttributes,
                'is_published' => $isPublished,
                'in_stock' => $inStock,
            ],
        );

        if ($reembed) {
            $this->embedAndStore($product);
        }

        $this->attributeVocabulary->forgetAll();

        return $product->fresh() ?? $product;
    }

    public function remove(int $productId): void
    {
        ProductCatalog::query()->where('product_id', $productId)->delete();
        $this->attributeVocabulary->forgetAll();
    }

    public function setPublished(int $productId, bool $published): void
    {
        $product = ProductCatalog::query()->where('product_id', $productId)->first();
        if ($product === null) {
            Log::warning('ProductIndexer: setPublished for missing product', ['product_id' => $productId]);

            return;
        }

        $product->is_published = $published;
        $product->save();

        if ($published) {
            $this->embedAndStore($product);
        }
    }

    public function setInStock(int $productId, bool $inStock): void
    {
        ProductCatalog::query()
            ->where('product_id', $productId)
            ->update(['in_stock' => $inStock]);
    }

    public function reindexAll(): int
    {
        $count = 0;
        ProductCatalog::query()->orderBy('id')->chunkById(50, function ($rows) use (&$count): void {
            foreach ($rows as $product) {
                /** @var ProductCatalog $product */
                try {
                    $this->embedAndStore($product);
                    $count++;
                } catch (LlmException) {
                    // Logged in embedAndStore. Leave embedding null and continue.
                }
            }
        });

        return $count;
    }

    public function embedAndStore(ProductCatalog $product): void
    {
        try {
            $text = $this->textBuilder->fromModel($product);
            $vector = $this->llm->embed($text, 'RETRIEVAL_DOCUMENT');
            $literal = PgVectorLiteral::from($vector);

            DB::statement(
                'UPDATE product_catalog SET embedding = ?::vector, updated_at = ? WHERE id = ?',
                [$literal, now(), $product->id]
            );
        } catch (LlmException $e) {
            Log::error('ProductIndexer: failed to generate embedding', [
                'product_id' => $product->product_id,
                'status' => $e->statusCode(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
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
