<?php

namespace Tests\Feature;

use App\Admin\AdminRoleRegistry;
use App\Enums\MerchantChangeRequestStatus;
use App\Enums\MerchantStatus;
use App\Models\AdminAuditLog;
use App\Models\AdminRole;
use App\Models\Merchant;
use App\Models\MerchantChangeRequest;
use App\Models\User;
use App\Notifications\MerchantProfileChangeReviewed;
use App\Notifications\MerchantProfileChangeSubmitted;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantProfileChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAuthorizationSeeder::class);
    }

    public function test_submitted_changes_stay_pending_and_only_become_live_after_admin_approval(): void
    {
        $merchant = $this->merchant(['business_name' => 'ABC Store', 'store_name' => 'ABC Store']);
        $this->actingAsMerchant($merchant);

        // store_name is resent unchanged, so only business_name is recorded as a change.
        $id = $this->postJson('/api/v1/merchant/profile/change-requests', [
            'business_name' => 'ABC Trading',
            'store_name' => 'ABC Store',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.fields', ['business_name'])
            ->assertJsonPath('data.changes.business_name', 'ABC Trading')
            ->assertJsonPath('data.original.business_name', 'ABC Store')
            ->assertJsonPath('data.submitted_by.id', $merchant->user_id)
            ->json('data.id');

        // Before approval the live record and every merchant API still return the approved value.
        $this->assertSame('ABC Store', $merchant->fresh()->business_name);
        $this->getJson('/api/v1/merchant/profile')
            ->assertOk()
            ->assertJsonPath('data.business_name', 'ABC Store')
            ->assertJsonPath('meta.pending_change_request.id', $id)
            ->assertJsonPath('meta.pending_change_request.changes.business_name', 'ABC Trading');
        $this->getJson('/api/auth/me')->assertJsonPath('data.merchant.business_name', 'ABC Store');

        $submitted = AdminAuditLog::query()->where('action', 'merchant.profile.change_requested')->sole();
        $this->assertSame($merchant->user_id, $submitted->actor_id);
        $this->assertSame($merchant->id, (int) $submitted->subject_id);
        $this->assertSame(['change_request_id' => $id, 'fields' => ['business_name']], $submitted->metadata);

        // The admin sees the pending change on the merchant list, summary and review queue.
        $admin = $this->actingAsAdmin();
        Merchant::factory()->create(['status' => MerchantStatus::Verified]);
        $this->getJson('/api/admin/merchants?has_pending_changes=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $merchant->id)
            ->assertJsonPath('data.0.business_name', 'ABC Store')
            ->assertJsonPath('data.0.pending_change_request.id', $id)
            ->assertJsonPath('data.0.pending_change_request.fields', ['business_name']);
        $this->getJson('/api/admin/merchants/summary')->assertJsonPath('data.pending_profile_changes', 1);
        $this->getJson('/api/admin/merchant-change-requests?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.merchant.store_name', 'ABC Store');

        // Comparison lists only the changed field: current approved value vs requested value.
        $this->getJson("/api/admin/merchant-change-requests/{$id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.comparison')
            ->assertJsonPath('data.comparison.0.field', 'business_name')
            ->assertJsonPath('data.comparison.0.current', 'ABC Store')
            ->assertJsonPath('data.comparison.0.requested', 'ABC Trading')
            ->assertJsonPath('data.comparison.0.changed', true);

        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by.id', $admin->id)
            ->assertJsonPath('meta.merchant.business_name', 'ABC Trading')
            ->assertJsonPath('meta.merchant.pending_change_request', null);

        $changeRequest = MerchantChangeRequest::query()->findOrFail($id);
        $this->assertSame(MerchantChangeRequestStatus::Approved, $changeRequest->status);
        $this->assertSame($admin->id, $changeRequest->reviewed_by);
        $this->assertNotNull($changeRequest->reviewed_at);
        $this->assertSame('ABC Trading', $merchant->fresh()->business_name);

        $approved = AdminAuditLog::query()->where('action', 'admin.merchants.profile_change_approved')->sole();
        $this->assertSame($admin->id, $approved->actor_id);
        $this->assertSame(['change_request_id' => $id, 'fields' => ['business_name']], $approved->metadata);
        // The service logs the decision itself; the generic mutation audit does not duplicate it.
        $this->assertFalse(AdminAuditLog::query()->where('action', 'admin.merchant-change-requests.status')->exists());
        $this->getJson("/api/admin/merchants/{$merchant->id}/onboarding-history")
            ->assertOk()
            ->assertJsonPath('data.0.description', "Profile change request #{$id} approved.");

        // A decided request cannot be approved or rejected again.
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'rejected', 'reason' => 'Too late'])
            ->assertStatus(422);
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'admin.merchants.profile_change_approved')->count());

        // After approval the merchant sees the new approved value on reload.
        $this->actingAsMerchant($merchant);
        $this->getJson('/api/auth/me')->assertJsonPath('data.merchant.business_name', 'ABC Trading');
        $this->getJson('/api/v1/merchant/profile')
            ->assertJsonPath('data.business_name', 'ABC Trading')
            ->assertJsonPath('meta.pending_change_request', null)
            ->assertJsonPath('meta.latest_change_request.status', 'approved');
    }

    public function test_rejected_changes_never_reach_the_live_record_and_the_reason_is_shown_to_the_merchant(): void
    {
        $merchant = $this->merchant(['business_name' => 'ABC Store', 'contact_phone' => '09171234567']);
        $this->actingAsMerchant($merchant);
        $id = $this->postJson('/api/v1/merchant/profile/change-requests', [
            'business_name' => 'ABC Trading',
            'contact_phone' => '09998887777',
        ])->assertCreated()->json('data.id');

        $admin = $this->actingAsAdmin();
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'rejected'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
        $this->assertTrue(MerchantChangeRequest::query()->findOrFail($id)->isPending());

        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", [
            'status' => 'rejected',
            'reason' => 'Business name does not match the DTI registration.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Business name does not match the DTI registration.')
            ->assertJsonPath('meta.merchant.business_name', 'ABC Store');

        $fresh = $merchant->fresh();
        $this->assertSame('ABC Store', $fresh->business_name);
        $this->assertSame('09171234567', $fresh->contact_phone);

        $rejected = AdminAuditLog::query()->where('action', 'admin.merchants.profile_change_rejected')->sole();
        $this->assertSame($admin->id, $rejected->actor_id);
        $this->assertSame('Business name does not match the DTI registration.', $rejected->metadata['reason']);
        $this->assertSame(['business_name', 'contact_phone'], $rejected->metadata['fields']);

        $this->actingAsMerchant($merchant);
        $this->getJson('/api/auth/me')->assertJsonPath('data.merchant.business_name', 'ABC Store');
        $this->getJson('/api/v1/merchant/profile')
            ->assertJsonPath('data.business_name', 'ABC Store')
            ->assertJsonPath('meta.pending_change_request', null)
            ->assertJsonPath('meta.latest_change_request.status', 'rejected')
            ->assertJsonPath('meta.latest_change_request.rejection_reason', 'Business name does not match the DTI registration.');
        $this->getJson("/api/v1/merchant/profile/change-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // Once the request is decided the merchant can submit a corrected one.
        $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => 'ABC Store Trading'])
            ->assertCreated();
    }

    public function test_submission_rules_protect_admin_fields_and_allow_one_pending_request(): void
    {
        $merchant = $this->merchant(['business_name' => 'ABC Store', 'tin' => '111-222-333-000', 'store_slug' => 'abc-store']);
        $this->actingAsMerchant($merchant);

        $this->postJson('/api/v1/merchant/profile/change-requests', [
            'business_name' => 'ABC Trading',
            'tin' => '999-999-999-999',
            'store_slug' => 'abc-trading',
            'status' => 'verified',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tin', 'store_slug', 'status']);

        $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => '', 'contact_phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_name', 'contact_phone']);

        $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => '  ABC Store  '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('changes');

        $this->assertSame(0, MerchantChangeRequest::query()->count());

        $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => 'ABC Trading'])->assertCreated();
        $this->postJson('/api/v1/merchant/profile/change-requests', ['store_name' => 'Another Name'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('changes');

        $this->assertSame(1, MerchantChangeRequest::query()->count());
        $fresh = $merchant->fresh();
        $this->assertSame('ABC Store', $fresh->business_name);
        $this->assertSame('111-222-333-000', $fresh->tin);
        $this->assertSame('abc-store', $fresh->store_slug);
        $this->assertSame(MerchantStatus::Verified, $fresh->status);
    }

    public function test_merchants_cannot_review_or_read_other_merchants_requests(): void
    {
        $owner = $this->merchant(['business_name' => 'ABC Store']);
        $other = $this->merchant(['business_name' => 'Other Store']);

        $this->actingAsMerchant($owner);
        $id = $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => 'ABC Trading'])
            ->assertCreated()
            ->json('data.id');

        // A merchant cannot approve their own change through the admin API.
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])->assertForbidden();
        $this->getJson('/api/admin/merchant-change-requests')->assertForbidden();

        $this->actingAsMerchant($other);
        $this->getJson("/api/v1/merchant/profile/change-requests/{$id}")->assertNotFound();
        $this->getJson('/api/v1/merchant/profile/change-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/merchant/profile')
            ->assertJsonPath('data.business_name', 'Other Store')
            ->assertJsonPath('meta.pending_change_request', null);

        $this->assertTrue(MerchantChangeRequest::query()->findOrFail($id)->isPending());
        $this->assertSame('ABC Store', $owner->fresh()->business_name);
    }

    public function test_admin_review_requires_merchants_manage_and_admins_have_no_merchant_profile(): void
    {
        $merchant = $this->merchant(['store_name' => 'ABC Store']);
        $this->actingAsMerchant($merchant);
        $id = $this->postJson('/api/v1/merchant/profile/change-requests', ['store_name' => 'ABC Trading'])->json('data.id');

        // The Admin role can view merchants but not manage them.
        $this->actingAsAdmin(AdminRoleRegistry::ADMIN);
        $this->getJson("/api/admin/merchant-change-requests/{$id}")->assertOk();
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])->assertForbidden();
        $this->assertSame('ABC Store', $merchant->fresh()->store_name);

        // Admin accounts have no store profile on the merchant API.
        $this->getJson('/api/v1/merchant/profile')->assertForbidden();
        $this->postJson('/api/v1/merchant/profile/change-requests', ['store_name' => 'Hijacked'])->assertForbidden();

        $this->actingAsAdmin(AdminRoleRegistry::MERCHANT_MANAGER);
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])->assertOk();
        $this->assertSame('ABC Trading', $merchant->fresh()->store_name);
    }

    public function test_multipart_line_endings_do_not_count_as_changes(): void
    {
        Storage::fake('local');
        $merchant = $this->merchant([
            'store_address' => "2451 Golda Bypass Suite 063\nMustafachester, ID 43450",
            'business_address' => "748 Hill Green\nPort Cameron, LA 62283",
        ]);
        $this->actingAsMerchant($merchant);

        // Browsers send multipart text with "\r\n" line breaks; an unchanged address must not be a change.
        $this->post('/api/v1/merchant/profile/change-requests', [
            'store_address' => "2451 Golda Bypass Suite 063\r\nMustafachester, ID 43450",
            'business_address' => "748 Hill Green\r\nPort Cameron, LA 62283",
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('changes');

        $this->post('/api/v1/merchant/profile/change-requests', [
            'store_address' => "2451 Golda Bypass Suite 063\r\nMustafachester, ID 43450",
            'store_logo' => UploadedFile::fake()->image('logo.png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.fields', ['store_logo']);
    }

    public function test_social_links_are_normalized_and_compared_without_empty_entries(): void
    {
        $merchant = $this->merchant(['social_links' => ['facebook' => 'https://facebook.com/abc']]);
        $this->actingAsMerchant($merchant);

        $this->postJson('/api/v1/merchant/profile/change-requests', [
            'social_links' => ['instagram' => '', 'facebook' => 'https://facebook.com/abc'],
        ])->assertStatus(422)->assertJsonValidationErrors('changes');

        $id = $this->postJson('/api/v1/merchant/profile/change-requests', [
            'social_links' => ['facebook' => 'https://facebook.com/abc', 'instagram' => 'https://instagram.com/abc'],
        ])->assertCreated()->json('data.id');

        $this->actingAsAdmin();
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])->assertOk();

        $this->assertSame(
            ['facebook' => 'https://facebook.com/abc', 'instagram' => 'https://instagram.com/abc'],
            $merchant->fresh()->social_links,
        );
    }

    public function test_uploaded_documents_stay_private_until_approval_then_replace_the_live_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $merchant = $this->merchant(['store_slug' => 'abc-store', 'store_logo_path' => 'merchants/abc-store/old-logo.png']);
        Storage::disk('public')->put('merchants/abc-store/old-logo.png', 'old logo');
        $other = $this->merchant();

        $this->actingAsMerchant($merchant);
        $id = $this->post('/api/v1/merchant/profile/change-requests', [
            'store_logo' => UploadedFile::fake()->image('new-logo.png', 200, 200),
            'business_permit' => UploadedFile::fake()->create('permit-2027.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.fields', ['store_logo', 'business_permit'])
            ->assertJsonPath('data.files.store_logo.name', 'new-logo.png')
            ->assertJsonPath('data.files.business_permit.mime', 'application/pdf')
            ->assertJsonMissingPath('data.files.store_logo.path')
            ->json('data.id');

        $pendingLogo = MerchantChangeRequest::query()->findOrFail($id)->files['store_logo']['path'];
        $pendingPermit = MerchantChangeRequest::query()->findOrFail($id)->files['business_permit']['path'];

        // Unapproved uploads are on the private disk only, and the live record still points at the old logo.
        Storage::disk('local')->assertExists([$pendingLogo, $pendingPermit]);
        $this->assertSame(['merchants/abc-store/old-logo.png'], Storage::disk('public')->allFiles());
        $this->assertSame('merchants/abc-store/old-logo.png', $merchant->fresh()->store_logo_path);

        $this->get("/api/v1/merchant/profile/change-requests/{$id}/files/store_logo")->assertOk();
        $this->get('/api/v1/merchant/profile/documents/store_logo')->assertOk()->assertStreamedContent('old logo');
        $this->get("/api/v1/merchant/profile/change-requests/{$id}/files/government_id")->assertNotFound();

        $this->actingAsMerchant($other);
        $this->get("/api/v1/merchant/profile/change-requests/{$id}/files/store_logo")->assertNotFound();

        $this->actingAsAdmin();
        $this->get("/api/admin/merchant-change-requests/{$id}/files/business_permit")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->getJson("/api/admin/merchant-change-requests/{$id}")
            ->assertJsonPath('data.comparison.0.field', 'store_logo')
            ->assertJsonPath('data.comparison.0.kind', 'file')
            ->assertJsonPath('data.comparison.0.current', 'old-logo.png')
            ->assertJsonPath('data.comparison.0.requested', 'new-logo.png')
            ->assertJsonPath('data.comparison.1.field', 'business_permit');

        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])->assertOk();

        $fresh = $merchant->fresh();
        $this->assertSame('merchants/abc-store/'.basename($pendingLogo), $fresh->store_logo_path);
        $this->assertSame('merchants/abc-store/'.basename($pendingPermit), $fresh->business_permit_path);
        Storage::disk('public')->assertExists([$fresh->store_logo_path, $fresh->business_permit_path]);
        // The previous live file is kept; the private pending copies are removed.
        Storage::disk('public')->assertExists('merchants/abc-store/old-logo.png');
        Storage::disk('local')->assertMissing([$pendingLogo, $pendingPermit]);
        $this->get("/api/admin/merchant-change-requests/{$id}/files/store_logo")->assertNotFound();
    }

    public function test_rejecting_discards_uploaded_files_and_invalid_uploads_are_refused(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $merchant = $this->merchant(['store_banner_path' => null]);
        $this->actingAsMerchant($merchant);

        $this->post('/api/v1/merchant/profile/change-requests', [
            'store_logo' => UploadedFile::fake()->create('logo.gif', 10, 'image/gif'),
            'government_id' => UploadedFile::fake()->create('id.pdf', 6000, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['store_logo', 'government_id']);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $id = $this->post('/api/v1/merchant/profile/change-requests', [
            'store_banner' => UploadedFile::fake()->image('banner.jpg', 1200, 400),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $pending = MerchantChangeRequest::query()->findOrFail($id)->files['store_banner']['path'];

        $this->actingAsAdmin();
        $this->getJson("/api/admin/merchant-change-requests/{$id}")
            ->assertJsonPath('data.comparison.0.current', null)
            ->assertJsonPath('data.comparison.0.requested', 'banner.jpg');
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'rejected', 'reason' => 'Banner is blurry.'])
            ->assertOk();

        Storage::disk('local')->assertMissing($pending);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($merchant->fresh()->store_banner_path);
    }

    public function test_merchant_can_withdraw_their_own_pending_request(): void
    {
        Storage::fake('local');
        $merchant = $this->merchant(['business_name' => 'ABC Store']);
        $other = $this->merchant();
        $this->actingAsMerchant($merchant);
        $id = $this->post('/api/v1/merchant/profile/change-requests', [
            'business_name' => 'ABC Trading',
            'store_banner' => UploadedFile::fake()->image('banner.png', 800, 300),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $pending = MerchantChangeRequest::query()->findOrFail($id)->files['store_banner']['path'];

        $this->actingAsMerchant($other);
        $this->postJson("/api/v1/merchant/profile/change-requests/{$id}/withdraw")->assertNotFound();
        $this->actingAsAdmin();
        $this->postJson("/api/v1/merchant/profile/change-requests/{$id}/withdraw")->assertForbidden();
        $this->assertTrue(MerchantChangeRequest::query()->findOrFail($id)->isPending());

        $user = $this->actingAsMerchant($merchant);
        $this->postJson("/api/v1/merchant/profile/change-requests/{$id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $changeRequest = MerchantChangeRequest::query()->findOrFail($id);
        $this->assertSame(MerchantChangeRequestStatus::Withdrawn, $changeRequest->status);
        $this->assertNotNull($changeRequest->withdrawn_at);
        $this->assertNull($changeRequest->reviewed_by);
        Storage::disk('local')->assertMissing($pending);
        $this->assertSame('ABC Store', $merchant->fresh()->business_name);

        $log = AdminAuditLog::query()->where('action', 'merchant.profile.change_withdrawn')->sole();
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame(['change_request_id' => $id, 'fields' => ['business_name', 'store_banner']], $log->metadata);

        $this->postJson("/api/v1/merchant/profile/change-requests/{$id}/withdraw")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/merchant/profile')
            ->assertJsonPath('meta.pending_change_request', null)
            ->assertJsonPath('meta.latest_change_request.status', 'withdrawn');

        $this->actingAsAdmin();
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])->assertStatus(422);
        $this->assertSame('ABC Store', $merchant->fresh()->business_name);

        // A withdrawn request no longer blocks a new submission.
        $this->actingAsMerchant($merchant);
        $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => 'ABC Corp'])->assertCreated();
    }

    public function test_reviewers_are_notified_of_submissions_and_the_merchant_of_each_decision(): void
    {
        Notification::fake();
        $superAdmin = $this->createAdmin(AdminRoleRegistry::SUPER_ADMIN);
        $manager = $this->createAdmin(AdminRoleRegistry::MERCHANT_MANAGER);
        $viewOnly = $this->createAdmin(AdminRoleRegistry::ADMIN);
        $inactive = $this->createAdmin(AdminRoleRegistry::SUPER_ADMIN);
        $inactive->update(['is_active' => false]);
        $merchant = $this->merchant(['business_name' => 'ABC Store']);
        $owner = $merchant->user()->firstOrFail();

        $this->actingAsMerchant($merchant);
        $id = $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => 'ABC Trading'])->json('data.id');

        Notification::assertSentTo(
            [$superAdmin, $manager],
            MerchantProfileChangeSubmitted::class,
            fn (MerchantProfileChangeSubmitted $notification, array $channels) => $notification->changeRequest->id === $id
                && $channels === ['database', 'mail'],
        );
        Notification::assertNotSentTo([$viewOnly, $inactive, $owner], MerchantProfileChangeSubmitted::class);

        Sanctum::actingAs($manager, ['admin'], 'sanctum');
        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])->assertOk();
        Notification::assertSentTo(
            $owner,
            MerchantProfileChangeReviewed::class,
            fn (MerchantProfileChangeReviewed $notification) => $notification->toArray($owner)['kind'] === 'merchant_profile_change_approved',
        );

        $this->actingAsMerchant($merchant);
        $second = $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => 'ABC Corp'])->json('data.id');
        Sanctum::actingAs($manager, ['admin'], 'sanctum');
        $this->patchJson("/api/admin/merchant-change-requests/{$second}/status", ['status' => 'rejected', 'reason' => 'Name not registered.'])->assertOk();
        Notification::assertSentTo(
            $owner,
            MerchantProfileChangeReviewed::class,
            fn (MerchantProfileChangeReviewed $notification) => $notification->toArray($owner)['kind'] === 'merchant_profile_change_rejected'
                && $notification->toArray($owner)['reason'] === 'Name not registered.',
        );
        Notification::assertSentToTimes($superAdmin, MerchantProfileChangeSubmitted::class, 2);
    }

    public function test_in_app_notifications_are_listed_per_user_and_can_be_marked_read(): void
    {
        $admin = $this->createAdmin(AdminRoleRegistry::SUPER_ADMIN);
        $merchant = $this->merchant(['business_name' => 'ABC Store', 'store_name' => 'ABC Shop']);

        $this->actingAsMerchant($merchant);
        $id = $this->postJson('/api/v1/merchant/profile/change-requests', ['business_name' => 'ABC Trading'])->json('data.id');

        Sanctum::actingAs($admin, ['admin'], 'sanctum');
        $notificationId = $this->getJson('/api/admin/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.kind', 'merchant_profile_change_submitted')
            ->assertJsonPath('data.0.message', 'ABC Shop requested changes to 1 field.')
            ->assertJsonPath('data.0.link', "/admin/merchants/{$merchant->id}")
            ->assertJsonPath('data.0.read_at', null)
            ->json('data.0.id');

        $this->postJson("/api/admin/notifications/{$notificationId}/read")->assertOk();
        $this->getJson('/api/admin/notifications?unread=1')
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread_count', 0);
        $this->assertFalse(AdminAuditLog::query()->where('action', 'like', 'admin.notifications.%')->exists());

        $this->patchJson("/api/admin/merchant-change-requests/{$id}/status", ['status' => 'approved'])->assertOk();

        $this->actingAsMerchant($merchant);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.kind', 'merchant_profile_change_approved')
            ->assertJsonPath('data.0.link', '/store-profile');
        // Notifications are scoped to their owner.
        $this->postJson("/api/v1/notifications/{$notificationId}/read")->assertNotFound();
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 0);
    }

    protected function merchant(array $attributes = []): Merchant
    {
        return Merchant::factory()->create(array_merge(['status' => MerchantStatus::Verified], $attributes));
    }

    protected function actingAsMerchant(Merchant $merchant): User
    {
        $user = $merchant->user()->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsAdmin(string $role = AdminRoleRegistry::SUPER_ADMIN): User
    {
        $admin = $this->createAdmin($role);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        return $admin;
    }

    protected function createAdmin(string $role): User
    {
        $admin = User::factory()->admin()->create(['email' => fake()->unique()->safeEmail()]);
        $admin->adminRoles()->sync([AdminRole::query()->where('slug', $role)->firstOrFail()->id]);

        return $admin->fresh(['adminRoles.permissions', 'adminPermissions']);
    }
}
