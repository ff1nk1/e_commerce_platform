<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockReservation;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockReservation> */
final class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

    public function definition(): array
    {
        return [
            'order_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'product_id' => Product::factory(),
            'store_id' => Store::factory(),
            'quantity' => 1,
            'status' => StockReservation::STATUS_HELD,
        ];
    }
}
