<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProductSnapshot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ProductSnapshotSeeder extends Seeder
{
    /**
     * Копирует товары из catalog DB в product_snapshots (id/name/price/store_id).
     */
    public function run(): void
    {
        try {
            $products = DB::connection('catalog')
                ->table('products')
                ->select(['id', 'name', 'price', 'store_id'])
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            $this->command?->error('Не удалось прочитать catalog DB: '.$e->getMessage());
            $this->command?->warn('Проверьте CATALOG_DB_* и что catalog_service_db доступен из order_app.');

            return;
        }

        if ($products->isEmpty()) {
            $this->command?->warn('В catalog нет товаров — сначала: docker exec catalog_service_app php artisan db:seed --force');

            return;
        }

        $count = 0;
        foreach ($products as $product) {
            ProductSnapshot::query()->updateOrCreate(
                ['id' => (int) $product->id],
                [
                    'name' => (string) $product->name,
                    'price' => (string) $product->price,
                    'store_id' => (int) $product->store_id,
                ]
            );
            $count++;
        }

        $this->command?->info("Synced {$count} product snapshots from catalog.");
    }
}
