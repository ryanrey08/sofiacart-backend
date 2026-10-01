<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('#########'),
            'address' => fake()->address(),
            'customer_type' => CustomerType::Regular,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function ofType(CustomerType $type): static
    {
        return $this->state(fn (): array => ['customer_type' => $type]);
    }
}
