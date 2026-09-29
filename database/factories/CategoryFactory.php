<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'merchant_id' => Merchant::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name.'-'.fake()->unique()->numerify('##')),
            'description' => fake()->sentence(),
        ];
    }
}
