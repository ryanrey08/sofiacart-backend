<?php

namespace Tests\Feature;

use App\Admin\AdminRoleRegistry;
use App\Enums\MerchantChangeRequestStatus;
use App\Enums\MerchantStatus;
use App\Models\AdminAuditLog;
use App\Models\AdminRole;
use App\Models\Merchant;
use App\Models\User;
use App\Notifications\MerchantProfileUpdatedByAdmin;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMerchantEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAuthorizationSeeder::class);
        Notification::fake();
    }

    public function test_admin_edits_are_live_immediately_without_a_change_request(): void
    {
        Storage::fake('public');
        $merchant = $this->merchant();
        $admin = $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);

        $response = $this->post("/api/admin/merchants/{$merchant->id}/profile", [
            'business_name' => 'ABC Trading',
            'store_name' => $merchant->store_name,
            'province' => 'Cebu',
            'city' => 'Cebu City',
            'tin' => '999-888-777-000',
            'government_id_type' => 'UMID',
            'owner_birth_date' => '1985-02-03',
            'government_id_expiry_date' => '2020-01-01',
            'store_logo' => UploadedFile::fake()->image('logo.png'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('message', 'Merchant details saved.')
            ->assertJsonPath('data.business_name', 'ABC Trading')
            ->assertJsonPath('data.city', 'Cebu City')
            ->assertJsonPath('data.tin', '999-888-777-000')
            ->assertJsonPath('data.owner_birth_date', '1985-02-03');
        $this->assertEqualsCanonicalizing(
            ['business_name', 'province', 'city', 'tin', 'government_id_type', 'owner_birth_date', 'government_id_expiry_date', 'store_logo'],
            $response->json('fields'),
        );

        $merchant->refresh();
        $this->assertSame('ABC Trading', $merchant->business_name);
        $this->assertSame('UMID', $merchant->government_id_type);
        $this->assertSame('2020-01-01', $merchant->government_id_expiry_date->toDateString());
        Storage::disk('public')->assertExists($merchant->store_logo_path);
        $this->assertSame(0, $merchant->changeRequests()->count());

        $log = AdminAuditLog::query()->where('action', 'admin.merchants.profile_updated')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($merchant->id, $log->subject_id);
        $this->assertContains('tin', $log->metadata['fields']);
        $this->assertSame(0, AdminAuditLog::query()->where('action', 'admin.merchants.profile.update')->count());
        $this->getJson("/api/admin/merchants/{$merchant->id}/onboarding-history")
            ->assertJsonPath('data.0.action', 'admin.merchants.profile_updated');

        Notification::assertSentTo($merchant->user, MerchantProfileUpdatedByAdmin::class);
    }

    public function test_merchant_edits_still_wait_for_approval(): void
    {
        $merchant = $this->merchant();
        Sanctum::actingAs($merchant->user);

        $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => 'Requested Name'])
            ->assertCreated()
            ->assertJsonPath('data.status', MerchantChangeRequestStatus::Pending->value);
        $this->assertSame('ABC Store', $merchant->fresh()->business_name);
        // Merchants cannot use the admin edit.
        $this->postJson("/api/admin/merchants/{$merchant->id}/profile", ['business_name' => 'Direct'])->assertForbidden();
        $this->assertSame('ABC Store', $merchant->fresh()->business_name);
    }

    public function test_admin_edit_validation(): void
    {
        $merchant = $this->merchant();
        $other = Merchant::factory()->create(['tin' => '111-222-333-000']);
        $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);
        $url = "/api/admin/merchants/{$merchant->id}/profile";

        $this->postJson($url, [
            'tin' => $other->tin,
            'business_category' => 'Gadgets',
            'province' => 'Cebu',
            'city' => 'Makati City',
            'contact_phone' => '12345',
            'owner_birth_date' => now()->addDay()->toDateString(),
            'store_slug' => 'new-slug',
            'status' => 'rejected',
        ])->assertStatus(422)->assertInvalid([
            'tin', 'business_category', 'city', 'contact_phone', 'owner_birth_date', 'store_slug', 'status',
        ]);

        $this->postJson($url, ['business_name' => 'ABC Store'])
            ->assertStatus(422)->assertInvalid('changes');
        // Keeping its own TIN is fine.
        $this->postJson($url, ['tin' => $merchant->tin, 'store_name' => 'New Store Name'])->assertOk();
    }

    public function test_admin_can_save_other_fields_of_a_store_with_unlisted_values(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['province' => 'Metro Manila', 'city' => 'Makati', 'business_category' => 'Retail']);
        $this->actingAsAdmin(AdminRoleRegistry::SUPER_ADMIN);

        $this->postJson("/api/admin/merchants/{$merchant->id}/profile", [
            'business_name' => 'Renamed',
            'province' => 'Metro Manila',
            'city' => 'Makati',
            'business_category' => 'Retail',
        ])->assertOk()->assertJsonPath('fields', ['business_name']);

        // Changing the city still requires a listed one.
        $this->postJson("/api/admin/merchants/{$merchant->id}/profile", ['city' => 'Makati Town'])
            ->assertStatus(422)->assertInvalid('city');
    }

    public function test_admin_edit_requires_merchants_manage(): void
    {
        $merchant = $this->merchant();

        $this->actingAsAdmin(AdminRoleRegistry::ADMIN);
        $this->postJson("/api/admin/merchants/{$merchant->id}/profile", ['business_name' => 'X'])->assertForbidden();

        $this->actingAsAdmin(AdminRoleRegistry::MERCHANT_MANAGER);
        $this->postJson("/api/admin/merchants/{$merchant->id}/profile", ['business_name' => 'X'])->assertOk();
    }

    protected function merchant(): Merchant
    {
        return Merchant::factory()->create([
            'status' => MerchantStatus::Verified,
            'business_name' => 'ABC Store',
            'store_name' => 'ABC Store',
            'tin' => '123-456-789-000',
        ]);
    }

    protected function actingAsAdmin(string $role): User
    {
        $admin = User::factory()->admin()->create(['email' => fake()->unique()->safeEmail()]);
        $admin->adminRoles()->sync([AdminRole::query()->where('slug', $role)->firstOrFail()->id]);
        $admin = $admin->fresh(['adminRoles.permissions', 'adminPermissions']);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        return $admin;
    }
}
