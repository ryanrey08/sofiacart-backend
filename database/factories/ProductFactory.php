<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Category;
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
            'status' => fake()->randomElement(ProductStatus::cases()),
            'price' => fake()->randomFloat(2, 50, 1000),
            'stock_quantity' => fake()->numberBetween(0, 200),
            'images' => ['products/default.png'],
        ];
    }
}
