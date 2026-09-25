<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MerchantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_registration_validates_payload(): void
    {
        Storage::fake('public');

        $response = $this->post('/api/merchant/register', [
            'name' => 'Owner Name',
            'email' => 'invalid-email',
            'password' => 'password',
            'password_confirmation' => 'password',
            'phone' => '12345',
            'business_name' => 'Acme Corp',
            'business_type' => 'Corporation',
            'business_permit_number' => 'BP-123',
            'tin' => 'TIN-123',
            'business_category' => 'Retail',
            'business_address' => '123 Main St',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'zip_code' => '1100',
            'store_name' => 'Acme Store',
            'store_slug' => 'Invalid Slug',
            'store_category' => 'Groceries',
            'store_address' => '123 Main St',
            'contact_phone' => '12345',
            'contact_email' => 'store@example.com',
            'owner_name' => 'Owner Name',
            'owner_position' => 'CEO',
            'owner_email' => 'owner@example.com',
            'owner_phone' => '12345',
            'owner_birth_date' => now()->addDay()->toDateString(),
            'government_id_type' => 'Passport',
            'government_id_number' => 'A1234567',
            'government_id_expiry_date' => now()->subDay()->toDateString(),
            'business_permit' => UploadedFile::fake()->image('permit.png'),
            'store_logo' => UploadedFile::fake()->image('logo.png'),
            'government_id' => UploadedFile::fake()->image('government-id.png'),
        ]);

        $response->assertStatus(422)
            ->assertInvalid(['email', 'phone', 'store_slug', 'contact_phone', 'owner_phone', 'owner_birth_date', 'government_id_expiry_date']);
    }
}
