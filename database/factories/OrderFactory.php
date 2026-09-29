<?php

namespace Database\Factories;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'customer_id' => Customer::factory(),
            'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
            'status' => fake()->randomElement(OrderStatus::cases()),
            'payment_status' => fake()->randomElement(OrderPaymentStatus::cases()),
            'total_amount' => fake()->randomFloat(2, 150, 2500),
            'notes' => fake()->sentence(),
            'ordered_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
