<?php

namespace Tests\Feature;

use App\Enums\MerchantStatus;
use App\Models\Merchant;
use App\Models\User;
use App\Notifications\VerifyMerchantEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
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

    public function test_registration_creates_an_unverified_pending_merchant_and_emails_the_registered_address(): void
    {
        Storage::fake('public');
        Notification::fake();

        $response = $this->post('/api/merchant/register', $this->validPayload(['email' => ' Owner@Example.com ']), ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('verification_email_sent', true)
            ->assertJsonPath('verification_expires_in_minutes', 60)
            ->assertJsonPath('user.email', 'owner@example.com')
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('merchant.status', MerchantStatus::Pending->value);

        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame(MerchantStatus::Pending, $user->merchant->status);

        $sentToken = null;
        Notification::assertSentTo($user, VerifyMerchantEmail::class, function (VerifyMerchantEmail $notification, array $channels, User $notifiable) use (&$sentToken): bool {
            $sentToken = $notification->token;

            return $channels === ['mail'] && $notifiable->routeNotificationFor('mail') === 'owner@example.com';
        });
        $this->assertStringNotContainsString($sentToken, $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_duplicate_registrations_do_not_create_a_second_account(): void
    {
        Storage::fake('public');
        Notification::fake();

        $this->post('/api/merchant/register', $this->validPayload(), ['Accept' => 'application/json'])->assertCreated();

        $this->post('/api/merchant/register', $this->validPayload([
            'email' => 'OWNER@example.com',
            'tin' => 'TIN-999',
            'store_slug' => 'another-store',
        ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->post('/api/merchant/register', $this->validPayload(['email' => 'second@example.com']), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tin', 'store_slug']);

        $this->assertSame(1, User::count());
        $this->assertSame(1, Merchant::count());
        Notification::assertCount(1);
    }

    public function test_registration_succeeds_when_the_verification_email_cannot_be_sent(): void
    {
        Storage::fake('public');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);

        $this->post('/api/merchant/register', $this->validPayload(), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('verification_email_sent', false)
            ->assertJsonPath('user.email_verified', false);

        $this->assertFalse(User::where('email', 'owner@example.com')->firstOrFail()->hasVerifiedEmail());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Owner Name',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone' => '09171234567',
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
            'store_slug' => 'acme-store',
            'store_category' => 'Groceries',
            'store_address' => '123 Main St',
            'contact_phone' => '09171234567',
            'contact_email' => 'store@example.com',
            'owner_name' => 'Owner Name',
            'owner_position' => 'CEO',
            'owner_email' => 'owner@example.com',
            'owner_phone' => '09171234567',
            'owner_birth_date' => '1990-01-01',
            'government_id_type' => 'Passport',
            'government_id_number' => 'A1234567',
            'government_id_expiry_date' => now()->addYear()->toDateString(),
            'business_permit' => UploadedFile::fake()->image('permit.png'),
            'store_logo' => UploadedFile::fake()->image('logo.png'),
            'government_id' => UploadedFile::fake()->image('government-id.png'),
            ...$overrides,
        ];
    }
}
