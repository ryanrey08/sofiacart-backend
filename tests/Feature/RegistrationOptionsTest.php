<?php

namespace Tests\Feature;

use App\Admin\AdminRoleRegistry;
use App\Enums\LegalDocumentAudience;
use App\Enums\MerchantStatus;
use App\Enums\RegistrationOptionType;
use App\Models\AdminRole;
use App\Models\City;
use App\Models\LegalDocument;
use App\Models\Merchant;
use App\Models\MerchantChangeRequest;
use App\Models\Province;
use App\Models\RegistrationOption;
use App\Models\User;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesLegalDocuments;
use Tests\TestCase;

class RegistrationOptionsTest extends TestCase
{
    use CreatesLegalDocuments;
    use RefreshDatabase;

    /** @var array{terms: LegalDocument, privacy: LegalDocument} */
    protected array $legal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAuthorizationSeeder::class);
    }

    public function test_public_catalog_lists_only_active_entries_in_order(): void
    {
        $category = RegistrationOption::query()->ofType(RegistrationOptionType::Category)->where('name', 'Food')->firstOrFail();
        $category->update(['is_active' => false]);
        RegistrationOption::query()->ofType(RegistrationOptionType::Category)->where('name', 'Home')->update(['sort_order' => 0]);
        RegistrationOption::query()->ofType(RegistrationOptionType::Category)->where('name', 'Beauty')->update(['sort_order' => 9]);
        $cebu = Province::query()->where('name', 'Cebu')->firstOrFail();
        $cebu->cities()->where('name', 'Mandaue City')->update(['is_active' => false]);
        Province::query()->where('name', 'Laguna')->update(['is_active' => false]);
        Province::create(['name' => 'Test Province', 'is_active' => true]);

        $response = $this->getJson('/api/registration-options')->assertOk();

        $categories = $response->json('data.categories');
        $this->assertNotContains('Food', $categories);
        $this->assertSame('Home', $categories[0]);
        $this->assertSame('Beauty', end($categories));
        $this->assertContains('Corporation', $response->json('data.business_types'));
        $this->assertContains('Passport', $response->json('data.government_id_types'));
        $provinces = collect($response->json('data.provinces'));
        $this->assertFalse($provinces->contains('name', 'Laguna'));
        $this->assertTrue($provinces->firstWhere('name', 'Cebu')['has_cities']);
        $this->assertTrue($provinces->firstWhere('name', 'Metro Manila')['has_cities']);
        $this->assertFalse($provinces->firstWhere('name', 'Test Province')['has_cities']);

        $this->getJson("/api/registration-options/provinces/{$cebu->id}/cities")
            ->assertOk()
            ->assertJsonCount(52, 'data')
            ->assertJsonFragment(['name' => 'Cebu City', 'kind' => 'city'])
            ->assertJsonFragment(['name' => 'Toledo City', 'kind' => 'city'])
            ->assertJsonFragment(['name' => 'Moalboal', 'kind' => 'municipality'])
            ->assertJsonMissing(['name' => 'Mandaue City']);
        $laguna = Province::query()->where('name', 'Laguna')->firstOrFail();
        $this->getJson("/api/registration-options/provinces/{$laguna->id}/cities")->assertNotFound();
    }

    public function test_registration_accepts_only_active_listed_options_and_a_city_of_the_province(): void
    {
        Storage::fake('legal');
        Storage::fake('public');
        Notification::fake();
        $this->legal = $this->publishLegalDocumentsFor(LegalDocumentAudience::Merchant);
        RegistrationOption::query()->ofType(RegistrationOptionType::Category)->where('name', 'Food')->update(['is_active' => false]);

        $this->post('/api/merchant/register', $this->registrationPayload([
            'business_type' => 'Conglomerate',
            'business_category' => 'Food',
            'store_category' => 'Retail',
            'government_id_type' => 'Library Card',
            'province' => 'Atlantis',
        ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertInvalid(['business_type', 'business_category', 'store_category', 'government_id_type', 'province']);

        // Provinces with listed cities require one of them.
        $this->post('/api/merchant/register', $this->registrationPayload(['province' => 'Cebu', 'city' => 'Quezon City']), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertInvalid(['city' => 'Select a city or municipality in Cebu.']);

        $this->post('/api/merchant/register', $this->registrationPayload(['province' => 'Cebu', 'city' => 'Cebu City']), ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertSame('Cebu City', Merchant::query()->firstOrFail()->city);
    }

    public function test_profile_changes_use_the_same_lists_and_check_the_city_against_the_current_province(): void
    {
        Notification::fake();
        Province::create(['name' => 'Test Province', 'is_active' => true]);
        $merchant = Merchant::factory()->create([
            'status' => MerchantStatus::Verified,
            'province' => 'Cebu',
            'city' => 'Cebu City',
            'business_category' => 'Retail',
        ]);
        Sanctum::actingAs($merchant->user);

        $this->postJson('/api/v1/merchant/profile/change-requests', ['business_category' => 'Gadgets'])
            ->assertStatus(422)->assertInvalid('business_category');
        $this->postJson('/api/v1/merchant/profile/change-requests', ['city' => 'Makati'])
            ->assertStatus(422)->assertInvalid('city');
        $this->postJson('/api/v1/merchant/profile/change-requests', ['province' => 'Metro Manila', 'city' => 'Makati'])
            ->assertStatus(422)->assertInvalid(['city' => 'Select a city or municipality in Metro Manila.']);
        // Provinces without listed cities (added by the Super Admin) accept a typed city.
        $this->postJson('/api/v1/merchant/profile/change-requests', ['province' => 'Test Province', 'city' => 'Any Town'])
            ->assertCreated()
            ->assertJsonPath('data.changes.province', 'Test Province');
        $this->postJson('/api/v1/merchant/profile/change-requests/'.MerchantChangeRequest::query()->latest('id')->value('id').'/withdraw')
            ->assertSuccessful();
        $this->postJson('/api/v1/merchant/profile/change-requests', ['province' => 'Metro Manila', 'city' => 'Makati City'])
            ->assertCreated()
            ->assertJsonPath('data.changes.province', 'Metro Manila');
        // Government ID type stays admin-controlled even though it is a managed list.
        $this->postJson('/api/v1/merchant/profile/change-requests', ['government_id_type' => 'Passport'])
            ->assertStatus(422)->assertInvalid('government_id_type');
    }

    public function test_admin_manages_options_with_usage_protection(): void
    {
        $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);
        Merchant::factory()->create(['business_category' => 'Beauty', 'store_category' => 'Beauty']);

        $groups = collect($this->getJson('/api/admin/registration-options')->assertOk()->json('data'));
        $categories = collect($groups->firstWhere('type', 'category')['options']);
        $beauty = $categories->firstWhere('name', 'Beauty');
        $this->assertSame(1, $beauty['stores_using']);

        $id = $this->postJson('/api/admin/registration-options', ['type' => 'category', 'name' => '  Pets  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pets')
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');
        $this->postJson('/api/admin/registration-options', ['type' => 'category', 'name' => 'pets'])->assertStatus(422);
        $this->postJson('/api/admin/registration-options', ['type' => 'colour', 'name' => 'Red'])->assertStatus(422);

        $this->patchJson("/api/admin/registration-options/{$id}", ['name' => 'Pet Supplies', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Pet Supplies')
            ->assertJsonPath('data.is_active', false);

        $ids = RegistrationOption::query()->ofType(RegistrationOptionType::Category)->orderByDesc('id')->pluck('id')->all();
        $this->putJson('/api/admin/registration-options/order', ['type' => 'category', 'ids' => $ids])->assertOk();
        $this->assertSame($id, RegistrationOption::query()->ofType(RegistrationOptionType::Category)->ordered()->value('id'));
        $this->putJson('/api/admin/registration-options/order', ['type' => 'category', 'ids' => [$id]])->assertStatus(422);

        $this->deleteJson("/api/admin/registration-options/{$beauty['id']}")->assertStatus(409);
        $this->deleteJson("/api/admin/registration-options/{$id}")->assertOk();
        $this->assertDatabaseMissing('registration_options', ['id' => $id]);
    }

    public function test_admin_manages_provinces_and_cities_with_usage_protection(): void
    {
        $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);

        $provinceId = $this->postJson('/api/admin/provinces', ['name' => 'Test Province', 'region' => 'Test Region'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/admin/provinces', ['name' => 'test province'])->assertStatus(422);
        $cityId = $this->postJson("/api/admin/provinces/{$provinceId}/cities", ['name' => 'Test City', 'kind' => 'city'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/admin/provinces/{$provinceId}/cities", ['name' => 'test city'])->assertStatus(422);
        $this->postJson("/api/admin/provinces/{$provinceId}/cities", ['name' => 'Test Barangay', 'kind' => 'barangay'])->assertStatus(422);

        Merchant::factory()->create(['province' => 'Test Province', 'city' => 'Test City']);

        $testProvince = collect($this->getJson('/api/admin/provinces?search=test%20prov')->assertOk()->json('data'))->firstWhere('id', $provinceId);
        $this->assertSame(1, $testProvince['cities_count']);
        $this->assertSame(1, $testProvince['stores_using']);
        $this->getJson("/api/admin/provinces/{$provinceId}/cities")->assertOk()->assertJsonPath('data.0.stores_using', 1);

        $this->deleteJson("/api/admin/cities/{$cityId}")->assertStatus(409);
        $this->deleteJson("/api/admin/provinces/{$provinceId}")->assertStatus(409);
        $this->patchJson("/api/admin/cities/{$cityId}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson("/api/admin/provinces/{$provinceId}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        $emptyId = $this->postJson('/api/admin/provinces', ['name' => 'Empty Province'])->json('data.id');
        $this->postJson("/api/admin/provinces/{$emptyId}/cities", ['name' => 'Empty Town']);
        $this->deleteJson("/api/admin/provinces/{$emptyId}")->assertOk();
        $this->assertSame(0, City::query()->where('province_id', $emptyId)->count());
    }

    public function test_admin_endpoints_require_settings_permissions(): void
    {
        $option = RegistrationOption::query()->firstOrFail();

        $this->actingAsAdmin(AdminRoleRegistry::MERCHANT_MANAGER);
        $this->getJson('/api/admin/registration-options')->assertForbidden();
        $this->getJson('/api/admin/provinces')->assertForbidden();

        $this->actingAsAdmin(AdminRoleRegistry::ADMIN);
        $this->getJson('/api/admin/registration-options')->assertOk();
        $this->getJson('/api/admin/provinces')->assertOk();
        $this->postJson('/api/admin/registration-options', ['type' => 'category', 'name' => 'Pets'])->assertForbidden();
        $this->patchJson("/api/admin/registration-options/{$option->id}", ['is_active' => false])->assertForbidden();
        $this->postJson('/api/admin/provinces', ['name' => 'Test Province'])->assertForbidden();

        $merchant = Merchant::factory()->create();
        Sanctum::actingAs($merchant->user);
        $this->getJson('/api/admin/registration-options')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function registrationPayload(array $overrides = []): array
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

    protected function actingAsAdmin(string $role): User
    {
        $admin = User::factory()->admin()->create(['email' => fake()->unique()->safeEmail()]);
        $admin->adminRoles()->sync([AdminRole::query()->where('slug', $role)->firstOrFail()->id]);
        Sanctum::actingAs($admin->fresh(['adminRoles.permissions', 'adminPermissions']), ['admin'], 'sanctum');

        return $admin;
    }
}
