<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

use App\Models\ProductCatalog;

/**
 * HTTP-safe product projection. The embedding vector is intentionally absent:
 * it must never reach an API response.
 */
final readonly class ProductCard
{
    /**
     * @param  array<mixed>  $attributes
     */
    public function __construct(
        public int $productId,
        public int $storeId,
        public ?string $storeName,
        public ?string $name,
        public ?string $description,
        public ?int $categoryId,
        public ?string $categoryName,
        public ?string $imageUrl,
        public float $price,
        public array $attributes,
        public bool $isPublished,
        public bool $inStock,
        public string $path,
    ) {}

    public static function fromModel(ProductCatalog $product): self
    {
        $productId = (int) $product->product_id;
        $attributes = $product->getAttribute('attributes');

        return new self(
            productId: $productId,
            storeId: (int) $product->store_id,
            storeName: $product->store_name,
            name: $product->name,
            description: $product->description,
            categoryId: $product->category_id !== null ? (int) $product->category_id : null,
            categoryName: $product->category_name,
            imageUrl: $product->image_url,
            price: (float) $product->price,
            attributes: is_array($attributes) ? $attributes : [],
            isPublished: (bool) $product->is_published,
            inStock: (bool) $product->in_stock,
            path: '/products/'.$productId,
        );
    }

    /**
     * @return array{
     *     product_id: int,
     *     store_id: int,
     *     store_name: string|null,
     *     name: string|null,
     *     description: string|null,
     *     category_id: int|null,
     *     category_name: string|null,
     *     image_url: string|null,
     *     price: float,
     *     attributes: array<mixed>,
     *     is_published: bool,
     *     in_stock: bool,
     *     path: string
     * }
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'store_id' => $this->storeId,
            'store_name' => $this->storeName,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'image_url' => $this->imageUrl,
            'price' => $this->price,
            'attributes' => $this->attributes,
            'is_published' => $this->isPublished,
            'in_stock' => $this->inStock,
            'path' => $this->path,
        ];
    }
}
