<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Stock;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class StockSeeder extends Seeder
{
    /**
     * Создаёт остатки для всех товаров на их store_id (как ждёт order.created / резерв).
     * Через StockService, чтобы в outbox ушёл stock.changed для OpenSearch.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        Stock::query()->truncate();
        Schema::enableForeignKeyConstraints();

        $products = Product::query()->orderBy('id')->get();

        if ($products->isEmpty()) {
            $this->command->warn('Нет товаров — сначала запустите ShopSeeder.');

            return;
        }

        /** @var StockService $stockService */
        $stockService = resolve(StockService::class);

        foreach ($products as $index => $product) {
            // Первый товар оставляем с 0 — удобно проверить stock.reservation_failed.
            $quantity = $index === 0 ? 0 : 100;

            $stockService->createStock([
                'product_id' => $product->id,
                'store_id' => $product->store_id,
                'quantity' => $quantity,
                'reserved' => 0,
            ]);
        }

        $this->command->info(sprintf(
            'Stock seeded for %d products (first product quantity=0 for shortage tests).',
            $products->count()
        ));
    }
}
