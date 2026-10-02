<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'order_id' => null,
            'reference' => 'PAY-'.fake()->unique()->numerify('########'),
            'gateway' => 'manual',
            'method' => fake()->randomElement([PaymentMethod::Gcash, PaymentMethod::Maya, PaymentMethod::BankTransfer]),
            'status' => PaymentStatus::Completed,
            'amount' => fake()->randomFloat(2, 100, 2500),
            'currency' => 'PHP',
            'paid_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Pending, 'paid_at' => null]);
    }
}
