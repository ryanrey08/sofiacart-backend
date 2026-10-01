<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'merchant_id' => Merchant::factory(),
            'category_id' => null,
            'name' => Str::title($name),
            'slug' => Str::slug($name.'-'.fake()->unique()->numerify('##')),
            'sku' => strtoupper(fake()->unique()->bothify('SKU###??')),
            'description' => fake()->sentence(),
            'short_description' => fake()->text(120),
            'full_description' => fake()->paragraph(),
            'status' => fake()->randomElement(ProductStatus::cases()),
            'price' => fake()->randomFloat(2, 50, 1000),
            'regular_price' => fake()->randomFloat(2, 50, 1000),
            'track_inventory' => true,
            'stock_quantity' => fake()->numberBetween(0, 200),
            'low_stock_threshold' => 5,
            'images' => ['products/default.png'],
        ];
    }
}
