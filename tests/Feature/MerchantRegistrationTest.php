<?php

namespace Tests\Feature;

use App\Enums\LegalDocumentAudience;
use App\Enums\LegalDocumentType;
use App\Enums\MerchantStatus;
use App\Models\LegalDocument;
use App\Models\Merchant;
use App\Models\User;
use App\Notifications\VerifyMerchantEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesLegalDocuments;
use Tests\TestCase;

class MerchantRegistrationTest extends TestCase
{
    use CreatesLegalDocuments;
    use RefreshDatabase;

    /** @var array{terms: LegalDocument, privacy: LegalDocument} */
    protected array $legal;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('legal');
        $this->legal = $this->publishLegalDocumentsFor(LegalDocumentAudience::Merchant);
    }

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
            'business_category' => 'Fashion',
            'business_address' => '123 Main St',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'zip_code' => '1100',
            'store_name' => 'Acme Store',
            'store_slug' => 'Invalid Slug',
            'store_category' => 'Home',
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
    public function test_registration_records_the_accepted_terms_and_acknowledged_privacy_versions(): void
    {
        Storage::fake('public');
        Notification::fake();
        $this->travelTo(now()->setMicrosecond(0));

        $this->post('/api/merchant/register', $this->validPayload(), ['Accept' => 'application/json', 'User-Agent' => 'RegistrationTest/1.0'])
            ->assertCreated();

        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $consents = $user->legalConsents()->orderBy('type')->get();

        $this->assertCount(2, $consents);
        [$privacy, $terms] = [$consents->firstWhere('type', LegalDocumentType::Privacy), $consents->firstWhere('type', LegalDocumentType::Terms)];
        $this->assertSame($this->legal['terms']->id, $terms->legal_document_id);
        $this->assertSame(1, $terms->version);
        $this->assertSame('accepted', $terms->action);
        $this->assertSame($this->legal['privacy']->id, $privacy->legal_document_id);
        $this->assertSame('acknowledged', $privacy->action);
        $this->assertSame('merchant_registration', $terms->context);
        $this->assertTrue($terms->accepted_at->equalTo(now()));
        $this->assertSame('RegistrationTest/1.0', $terms->user_agent);
    }

    public function test_registration_requires_accepting_the_terms_and_acknowledging_the_privacy_policy(): void
    {
        Storage::fake('public');

        $this->post('/api/merchant/register', $this->validPayload([
            'accept_terms' => '0',
            'acknowledge_privacy' => null,
        ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertInvalid([
                'accept_terms' => 'You must accept the Merchant Terms and Conditions to register.',
                'acknowledge_privacy' => 'You must acknowledge',
            ]);

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
        $this->assertDatabaseCount('user_legal_consents', 0);
    }

    public function test_registration_rejects_versions_that_are_not_the_published_ones(): void
    {
        Storage::fake('public');
        $buyerTerms = $this->publishedLegalDocument(LegalDocumentAudience::Buyer, LegalDocumentType::Terms);

        // A buyer document, or a version that has since been replaced, cannot be accepted.
        $this->post('/api/merchant/register', $this->validPayload(['terms_document_id' => (string) $buyerTerms->id]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertInvalid(['terms_document_id' => 'has been updated']);

        $this->legal['terms']->forceFill(['status' => 'superseded', 'is_current' => null])->save();
        $this->publishedLegalDocument(LegalDocumentAudience::Merchant, LegalDocumentType::Terms, version: 2);

        $this->post('/api/merchant/register', $this->validPayload(), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertInvalid(['terms_document_id' => 'has been updated']);

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_registration_is_unavailable_while_the_terms_are_unpublished(): void
    {
        Storage::fake('public');
        $this->legal['terms']->forceFill(['status' => 'unpublished', 'is_current' => null])->save();

        $this->post('/api/merchant/register', $this->validPayload(), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertInvalid(['terms_document_id' => 'temporarily unavailable']);

        // The requirement can be switched off by configuration (no consent records are written then).
        config(['legal_documents.require_registration_acceptance' => false]);
        Notification::fake();

        $this->post('/api/merchant/register', $this->validPayload(), ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseCount('user_legal_consents', 0);
    }

    public function test_registration_images_and_documents_may_be_up_to_10_mb_each(): void
    {
        Storage::fake('public');
        Notification::fake();

        $this->post('/api/merchant/register', $this->validPayload([
            'store_logo' => UploadedFile::fake()->image('logo.png')->size(10241),
            'government_id' => UploadedFile::fake()->create('id.pdf', 10241, 'application/pdf'),
        ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertInvalid(['store_logo', 'government_id'])
            ->assertValid(['business_permit']);

        $this->post('/api/merchant/register', $this->validPayload([
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 10240, 'application/pdf'),
            'store_logo' => UploadedFile::fake()->image('logo.png')->size(10240),
            'store_banner' => UploadedFile::fake()->image('banner.jpg')->size(10240),
            'government_id' => UploadedFile::fake()->image('government-id.png')->size(10240),
        ]), ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_platform_subdomains_cannot_be_used_as_a_store_url(): void
    {
        Storage::fake('public');

        foreach (['www', 'api', 'store-api', 'admin'] as $slug) {
            $this->post('/api/merchant/register', $this->validPayload(['store_slug' => $slug]), ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertInvalid(['store_slug' => 'This store URL is reserved.']);
        }

        $this->post('/api/merchant/register', $this->validPayload(['store_slug' => str_repeat('a', 64)]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertInvalid(['store_slug' => 'may not be longer than 63']);

        $this->assertDatabaseCount('merchants', 0);
    }

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
            'business_category' => 'Fashion',
            'business_address' => '123 Main St',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'zip_code' => '1100',
            'store_name' => 'Acme Store',
            'store_slug' => 'acme-store',
            'store_category' => 'Home',
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
            'accept_terms' => '1',
            'terms_document_id' => (string) $this->legal['terms']->id,
            'acknowledge_privacy' => '1',
            'privacy_document_id' => (string) $this->legal['privacy']->id,
            ...$overrides,
        ];
    }
}
