<?php

namespace Database\Factories;

use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    public function definition(): array
    {
        $storeName = fake()->unique()->company();
        $slug = Str::slug($storeName.'-'.fake()->unique()->numerify('###'));

        return [
            'user_id' => User::factory()->state([
                'role' => UserRole::Merchant,
                'name' => fake()->name(),
                'phone' => '09'.fake()->numerify('#########'),
            ]),
            'business_name' => fake()->company(),
            'business_type' => fake()->randomElement(['Sole Proprietorship', 'Corporation', 'Partnership']),
            'business_permit_number' => fake()->bothify('BP-#####'),
            'tin' => fake()->unique()->numerify('###-###-###-###'),
            'business_category' => fake()->randomElement(['Retail', 'Food', 'Fashion']),
            'business_address' => fake()->address(),
            'city' => fake()->city(),
            'province' => fake()->state(),
            'zip_code' => fake()->postcode(),
            'business_permit_path' => 'merchants/'.$slug.'/permit.pdf',
            'store_name' => $storeName,
            'store_slug' => $slug,
            'store_category' => fake()->randomElement(['Groceries', 'Electronics', 'Beauty']),
            'store_description' => fake()->sentence(),
            'store_address' => fake()->address(),
            'contact_phone' => '09'.fake()->numerify('#########'),
            'contact_email' => fake()->unique()->safeEmail(),
            'store_logo_path' => 'merchants/'.$slug.'/logo.png',
            'store_banner_path' => 'merchants/'.$slug.'/banner.png',
            'social_links' => [
                'facebook' => 'https://facebook.com/'.Str::slug($storeName),
                'instagram' => 'https://instagram.com/'.Str::slug($storeName),
            ],
            'owner_name' => fake()->name(),
            'owner_position' => fake()->jobTitle(),
            'owner_email' => fake()->unique()->safeEmail(),
            'owner_phone' => '09'.fake()->numerify('#########'),
            'owner_birth_date' => fake()->dateTimeBetween('-50 years', '-21 years')->format('Y-m-d'),
            'government_id_type' => fake()->randomElement(['Passport', 'Drivers License', 'National ID']),
            'government_id_number' => fake()->bothify('ID-########'),
            'government_id_expiry_date' => fake()->dateTimeBetween('+1 year', '+10 years')->format('Y-m-d'),
            'government_id_path' => 'merchants/'.$slug.'/government-id.png',
            'status' => MerchantStatus::Verified,
        ];
    }
}
