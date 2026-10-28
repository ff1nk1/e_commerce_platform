<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stock> */
final class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'store_id' => Store::factory(),
            'quantity' => 100,
            'reserved' => 0,
        ];
    }
}
