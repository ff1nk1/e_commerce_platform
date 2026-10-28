<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\CatalogService;
use App\Services\ProductService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
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
        resolve(CatalogService::class)->clearCategoryCache();
        resolve(ProductService::class)->clearProductCache();
    }
}
