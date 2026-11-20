<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'sku' => fake()->unique()->regexify('[A-Z]{3}-[0-9]{3}'),
            'type' => ProductType::OneTime,
            'price' => fake()->randomFloat(2, 50, 2000),
            'is_active' => true,
        ];
    }
}
