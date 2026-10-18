<?php

namespace App\Services\Catalog;

use App\Models\ProductCatalog;
use App\Services\Llm\Contracts\LlmClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductIndexer
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ProductTextBuilder $textBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Kafka product data payload
     */
    public function upsertFromEvent(array $data, bool $reembed = true): ProductCatalog
    {
        $productId = (int) ($data['id'] ?? $data['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new \InvalidArgumentException('product event missing id');
        }

        $storeId = (int) ($data['store_id'] ?? data_get($data, 'store.id') ?? 0);
        $storeName = data_get($data, 'store.name')
            ?? ($data['store_name'] ?? null);
        $categoryId = $data['category_id'] ?? data_get($data, 'category.id');
        $categoryName = data_get($data, 'category.name')
            ?? ($data['category_name'] ?? null);

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

        $inStock = array_key_exists('in_stock', $data)
            ? (bool) $data['in_stock']
            : (array_key_exists('quantity', $data) ? ((int) $data['quantity']) > 0 : true);

        /** @var ProductCatalog $product */
        $product = ProductCatalog::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'store_id' => $storeId,
                'store_name' => $storeName !== null ? (string) $storeName : null,
                'name' => (string) ($data['name'] ?? ''),
                'description' => isset($data['description']) ? (string) $data['description'] : null,
                'category_id' => $categoryId !== null ? (int) $categoryId : null,
                'category_name' => $categoryName !== null ? (string) $categoryName : null,
                'image_url' => isset($data['image_url']) ? (string) $data['image_url'] : null,
                'price' => isset($data['price']) ? (float) $data['price'] : 0,
                'attributes' => $normalizedAttributes,
                'is_published' => $isPublished,
                'in_stock' => $inStock,
            ],
        );

        if ($reembed) {
            $this->embedAndStore($product);
        }

        return $product->fresh() ?? $product;
    }

    public function remove(int $productId): void
    {
        ProductCatalog::query()->where('product_id', $productId)->delete();
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
                $this->embedAndStore($product);
                $count++;
            }
        });

        return $count;
    }

    public function embedAndStore(ProductCatalog $product): void
    {
        $text = $this->textBuilder->fromModel($product);
        $vector = $this->llm->embed($text, 'RETRIEVAL_DOCUMENT');
        $literal = $this->toVectorLiteral($vector);

        DB::statement(
            'UPDATE product_catalog SET embedding = ?::vector, updated_at = ? WHERE id = ?',
            [$literal, now(), $product->id]
        );
    }

    /**
     * @param  list<float>  $vector
     */
    private function toVectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn (float $v): string => sprintf('%.8F', $v),
            $vector
        )).']';
    }
}
