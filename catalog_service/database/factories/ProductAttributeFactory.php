<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductAttribute> */
final class ProductAttributeFactory extends Factory
{
    protected $model = ProductAttribute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->word(),
            'value' => fake()->word(),
        ];
    }
}
