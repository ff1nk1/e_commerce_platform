<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Services\ProductService;
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
            $this->command->warn('Нет товаров — сначала ShopSeeder.');

            return;
        }

        /** @var ProductService $productsService */
        $productsService = resolve(ProductService::class);

        foreach ($products as $product) {
            // updateProduct публикует product.updated с актуальным payload.
            $productsService->updateProduct($product, [
                'name' => $product->name,
            ]);
        }

        $this->command->info(sprintf(
            'Republished %d products to Kafka (product.updated).',
            $products->count()
        ));
    }
}
