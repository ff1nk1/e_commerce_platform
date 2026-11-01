<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DTO\Catalog\ProductData;
use App\Models\Product;
use App\Services\Products\UpdateProductService;
use Illuminate\Database\Seeder;

/**
 * Публикует product.updated в Kafka для всех товаров
 * (order snapshots, OpenSearch, recommendation product_catalog).
 */
final class RepublishProductsSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::query()->with(['store', 'category'])->orderBy('id')->get();

        if ($products->isEmpty()) {
            $this->command?->warn('Нет товаров — сначала ShopSeeder.');

            return;
        }

        /** @var UpdateProductService $productsService */
        $productsService = resolve(UpdateProductService::class);

        foreach ($products as $product) {
            // update публикует product.updated с актуальным payload.
            $productsService->handle($product, ProductData::fromArray([
                'name' => $product->name,
            ]));
        }

        $this->command?->info(sprintf(
            'Republished %d products to Kafka (product.updated).',
            $products->count()
        ));
    }
}
