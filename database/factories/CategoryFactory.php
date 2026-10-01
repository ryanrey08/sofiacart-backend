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
            'sort_order' => 0,
            'is_active' => true,
            'show_in_nav' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function childOf(Category $parent): static
    {
        return $this->state(fn (): array => [
            'merchant_id' => $parent->merchant_id,
            'parent_id' => $parent->id,
        ]);
    }
}
