<?php

namespace Database\Seeders;

use App\Services\CatalogService;
use App\Services\ProductService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            ShopSeeder::class,
            ProductAttributeSeeder::class,
            StockSeeder::class,
        ]);

        // Safety net after StockSeeder: Redis must match freshly seeded DB.
        app(CatalogService::class)->clearCategoryCache();
        app(ProductService::class)->clearProductCache();
    }
}
