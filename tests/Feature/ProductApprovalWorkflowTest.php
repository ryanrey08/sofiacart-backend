<?php

namespace Tests\Feature;

use App\Admin\AdminRoleRegistry;
use App\Enums\MerchantStatus;
use App\Enums\ProductChangeRequestStatus;
use App\Enums\ProductStatus;
use App\Models\AdminAuditLog;
use App\Models\AdminRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Notifications\ProductReviewed;
use App\Notifications\ProductSubmittedForReview;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAuthorizationSeeder::class);
    }

    public function test_a_new_merchant_product_is_pending_unpublished_and_reviewers_are_notified(): void
    {
        Notification::fake();
        $superAdmin = $this->createAdmin(AdminRoleRegistry::SUPER_ADMIN);
        $viewOnlyAdmin = $this->createAdmin(AdminRoleRegistry::ADMIN);
        $merchant = $this->merchant();
        $this->actingAsMerchant($merchant);

        $id = $this->postJson('/api/v1/products', $this->productPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.listing_status', 'pending_approval')
            ->assertJsonPath('data.approval.is_approved', false)
            ->assertJsonPath('data.approval.approved_at', null)
            ->json('data.id');

        $product = Product::findOrFail($id);
        $this->assertNotNull($product->submitted_at);
        $this->assertNull($product->approved_at);
        $this->assertTrue(AdminAuditLog::query()->where('action', 'product.submitted_for_approval')->where('subject_id', $id)->exists());

        Notification::assertSentTo($superAdmin, ProductSubmittedForReview::class);
        Notification::assertNotSentTo($viewOnlyAdmin, ProductSubmittedForReview::class);

        // A draft is not submitted and not announced.
        $this->postJson('/api/v1/products', $this->productPayload(['slug' => 'draft-one', 'sku' => 'DRAFT-1', 'status' => 'draft']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.approval.submitted_at', null);
    }

    public function test_merchants_cannot_publish_or_approve_their_own_products(): void
    {
        $merchant = $this->merchant();
        $this->actingAsMerchant($merchant);

        $this->postJson('/api/v1/products', $this->productPayload(['status' => 'active']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/v1/products', $this->productPayload(['status' => 'rejected']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/v1/products', $this->productPayload(['approved_at' => now()->toISOString(), 'reviewed_by' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors(['approved_at', 'reviewed_by']);

        $product = Product::factory()->create(['merchant_id' => $merchant->id, 'status' => ProductStatus::Draft]);
        $this->patchJson("/api/v1/products/{$product->id}", ['status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/products/{$product->id}", ['approved_at' => now()->toISOString()])
            ->assertUnprocessable()->assertJsonValidationErrors('approved_at');

        // The admin review endpoints reject merchant tokens outright.
        $product->update(['status' => ProductStatus::PendingApproval]);
        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'approved'])->assertForbidden();

        $fresh = $product->fresh();
        $this->assertSame(ProductStatus::PendingApproval, $fresh->status);
        $this->assertNull($fresh->approved_at);
    }

    public function test_only_admins_with_products_approve_can_review(): void
    {
        $product = $this->pendingProduct();

        $this->actingAsAdmin(AdminRoleRegistry::ADMIN);
        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'approved'])->assertForbidden();

        $this->actingAsAdmin(AdminRoleRegistry::MERCHANT_MANAGER);
        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'approved'])->assertForbidden();

        // A Super Admin signed in with a non-admin token (e.g. a merchant-portal token) cannot review either.
        Sanctum::actingAs($this->createAdmin(AdminRoleRegistry::SUPER_ADMIN), ['merchant'], 'sanctum');
        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'approved'])->assertForbidden();

        $this->assertSame(ProductStatus::PendingApproval, $product->fresh()->status);
    }

    public function test_super_admin_approves_a_pending_product_and_the_decision_is_recorded(): void
    {
        Notification::fake();
        $product = $this->pendingProduct();
        $admin = $this->actingAsAdmin();

        $this->getJson('/api/admin/products?review=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id);

        $this->patchJson("/api/admin/products/{$product->id}/approval", [
            'status' => 'approved',
            'submitted_at' => $product->submitted_at->toISOString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.listing_status', 'published')
            ->assertJsonPath('data.approval.is_approved', true)
            ->assertJsonPath('data.approval.reviewed_by.id', $admin->id);

        $fresh = $product->fresh();
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame($admin->id, $fresh->reviewed_by);

        $log = AdminAuditLog::query()->where('action', 'admin.products.approved')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($product->id, (int) $log->subject_id);
        // The service logs the decision; the generic admin mutation audit does not duplicate it.
        $this->assertFalse(AdminAuditLog::query()->where('action', 'admin.products.approval')->exists());

        Notification::assertSentTo($product->merchant->user, ProductReviewed::class, fn (ProductReviewed $n) => $n->approved);

        // Decisions are final: a second approval (e.g. a double click or a second admin) is refused.
        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'rejected', 'reason' => 'Late'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('meta.review_history.0.action', 'admin.products.approved')
            ->assertJsonPath('meta.review_history.0.actor.id', $admin->id);
    }

    public function test_rejection_requires_a_reason_and_the_merchant_can_fix_and_resubmit(): void
    {
        Notification::fake();
        $product = $this->pendingProduct();
        $this->actingAsAdmin();

        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'rejected', 'reason' => 'Photos are blurry.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.approval.rejection_reason', 'Photos are blurry.')
            ->assertJsonPath('data.approval.is_approved', false);

        Notification::assertSentTo($product->merchant->user, ProductReviewed::class, fn (ProductReviewed $n) => ! $n->approved);
        $this->assertSame('Photos are blurry.', AdminAuditLog::query()->where('action', 'admin.products.rejected')->sole()->metadata['reason']);

        $this->actingAsMerchant($product->merchant);
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.listing_status', 'rejected')
            ->assertJsonPath('data.approval.rejection_reason', 'Photos are blurry.');

        // A rejected product is not live, so the merchant edits it directly, then resubmits.
        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Sharper Photos Product'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sharper Photos Product')
            ->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/v1/products/{$product->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');
        $this->postJson("/api/v1/products/{$product->id}/submit")
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAsAdmin();
        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.approval.rejection_reason', null);
    }

    public function test_a_pending_product_is_locked_until_withdrawn_and_stale_reviews_conflict(): void
    {
        $product = $this->pendingProduct();
        $this->actingAsMerchant($product->merchant);

        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Changed While Pending'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        // Operational fields (stock) still apply while pending.
        $this->patchJson("/api/v1/products/{$product->id}", ['stock_quantity' => 3])
            ->assertOk()->assertJsonPath('data.stock_quantity', 3);

        $submittedAt = $product->fresh()->submitted_at->toISOString();
        $this->postJson("/api/v1/products/{$product->id}/withdraw")
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Edited After Withdraw'])->assertOk();
        $this->travel(5)->seconds();
        $this->postJson("/api/v1/products/{$product->id}/submit")->assertOk();

        // The admin opened the earlier submission; approving the newer, unseen one is refused.
        $this->actingAsAdmin();
        $this->patchJson("/api/admin/products/{$product->id}/approval", ['status' => 'approved', 'submitted_at' => $submittedAt])
            ->assertStatus(409);
        $this->assertSame(ProductStatus::PendingApproval, $product->fresh()->status);
    }

    public function test_edits_to_an_approved_product_wait_for_review_while_the_approved_version_stays_live(): void
    {
        Notification::fake();
        $merchant = $this->merchant();
        $product = $this->approvedProduct($merchant, ['name' => 'Classic Mug', 'price' => 100, 'regular_price' => 100, 'stock_quantity' => 5]);
        $this->actingAsMerchant($merchant);

        $this->patchJson("/api/v1/products/{$product->id}", [
            'name' => 'Classic Mug v2',
            'regular_price' => 120,
            'stock_quantity' => 9,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Classic Mug')
            ->assertJsonPath('data.price', '100.00')
            ->assertJsonPath('data.stock_quantity', 9)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.pending_change_request.status', 'pending')
            ->assertJsonPath('data.pending_change_request.changes.name', 'Classic Mug v2')
            ->assertJsonPath('data.pending_change_request.changes.price', '120.00');

        $live = $product->fresh();
        $this->assertSame('Classic Mug', $live->name);
        $this->assertSame('100.00', $live->price);
        $this->assertSame(ProductStatus::Active, $live->status);

        // One pending edit at a time.
        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Classic Mug v3'])
            ->assertUnprocessable()->assertJsonValidationErrors('changes');
        // Resending the live values (a full-form save with only stock edits) stages nothing new.
        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Classic Mug', 'stock_quantity' => 9])
            ->assertOk()
            ->assertJsonPath('data.pending_change_request.changes.name', 'Classic Mug v2');
        $this->assertSame(1, $product->changeRequests()->count());
        // An approved product can only toggle between active and archived.
        $this->patchJson("/api/v1/products/{$product->id}", ['status' => 'draft'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/products/{$product->id}", ['status' => 'archived'])
            ->assertOk()->assertJsonPath('data.listing_status', 'hidden');
        $this->patchJson("/api/v1/products/{$product->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.listing_status', 'published');

        $changeRequestId = $product->pendingChangeRequest()->firstOrFail()->id;

        // Merchants cannot review their own edits.
        $this->patchJson("/api/admin/product-change-requests/{$changeRequestId}/status", ['status' => 'approved'])->assertForbidden();

        $admin = $this->actingAsAdmin();
        $this->getJson('/api/admin/products?review=pending')
            ->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.pending_change_request.comparison.0.field', 'name')
            ->assertJsonPath('data.pending_change_request.comparison.0.current', 'Classic Mug')
            ->assertJsonPath('data.pending_change_request.comparison.0.requested', 'Classic Mug v2');

        $this->patchJson("/api/admin/product-change-requests/{$changeRequestId}/status", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('meta.product.name', 'Classic Mug v2')
            ->assertJsonPath('meta.product.price', '120.00')
            ->assertJsonPath('meta.product.pending_change_request', null);

        $live = $product->fresh();
        $this->assertSame('Classic Mug v2', $live->name);
        $this->assertSame(9, $live->stock_quantity);
        $this->assertSame(ProductStatus::Active, $live->status);
        $this->assertSame($admin->id, AdminAuditLog::query()->where('action', 'admin.products.change_approved')->sole()->actor_id);
        Notification::assertSentTo($merchant->user, ProductReviewed::class, fn (ProductReviewed $n) => $n->approved && $n->changeRequest !== null);

        $this->patchJson("/api/admin/product-change-requests/{$changeRequestId}/status", ['status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_rejected_or_withdrawn_edits_never_reach_the_live_product(): void
    {
        Storage::fake('public');
        $merchant = $this->merchant();
        $product = $this->approvedProduct($merchant, ['name' => 'Linen Shirt']);
        $this->actingAsMerchant($merchant);

        $this->post("/api/v1/products/{$product->id}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->image('new.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.pending_change_request.fields', ['images']);
        $upload = $product->pendingChangeRequest()->firstOrFail()->images['paths'][0];
        Storage::disk('public')->assertExists($upload);
        $this->assertSame(0, $product->imageRecords()->count());

        $this->actingAsAdmin();
        $changeRequestId = $product->pendingChangeRequest()->firstOrFail()->id;
        $this->patchJson("/api/admin/product-change-requests/{$changeRequestId}/status", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson("/api/admin/product-change-requests/{$changeRequestId}/status", ['status' => 'rejected', 'reason' => 'Wrong product photo.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        Storage::disk('public')->assertMissing($upload);
        $this->assertSame(0, $product->imageRecords()->count());

        $this->actingAsMerchant($merchant);
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.pending_change_request', null)
            ->assertJsonPath('data.latest_change_request.status', 'rejected')
            ->assertJsonPath('data.latest_change_request.rejection_reason', 'Wrong product photo.');

        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Linen Shirt XL'])->assertOk();
        $this->postJson("/api/v1/products/{$product->id}/change-request/withdraw")
            ->assertOk()
            ->assertJsonPath('data.pending_change_request', null)
            ->assertJsonPath('data.latest_change_request.status', 'withdrawn');
        $this->assertSame('Linen Shirt', $product->fresh()->name);
        $this->assertSame(ProductChangeRequestStatus::Withdrawn, $product->changeRequests()->latest('id')->first()->status);
    }

    public function test_other_merchants_cannot_touch_a_product_and_admins_use_the_admin_endpoints(): void
    {
        $product = $this->pendingProduct();
        $this->actingAsMerchant($this->merchant());

        $this->postJson("/api/v1/products/{$product->id}/withdraw")->assertNotFound();
        $this->postJson("/api/v1/products/{$product->id}/submit")->assertNotFound();

        Sanctum::actingAs($this->createAdmin(AdminRoleRegistry::SUPER_ADMIN), ['admin'], 'sanctum');
        $this->postJson("/api/v1/products/{$product->id}/withdraw")->assertForbidden();
        // The generic admin product update cannot publish either.
        $this->patchJson("/api/admin/products/{$product->id}", ['status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_merchant_orders_only_accept_approved_active_products(): void
    {
        $merchant = $this->merchant();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        // Status alone is never trusted: an active row without an approval is not sellable.
        $unapproved = Product::factory()->create([
            'merchant_id' => $merchant->id, 'status' => ProductStatus::Active, 'approved_at' => null, 'price' => 50, 'stock_quantity' => 5,
        ]);
        $approved = $this->approvedProduct($merchant, ['price' => 50, 'stock_quantity' => 5]);
        $this->actingAsMerchant($merchant);

        $this->postJson('/api/v1/orders', ['customer_id' => $customer->id, 'items' => [['product_id' => $unapproved->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->postJson('/api/v1/orders', ['customer_id' => $customer->id, 'items' => [['product_id' => $approved->id, 'quantity' => 1]]])
            ->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ceramic Bowl',
            'slug' => 'ceramic-bowl',
            'sku' => 'BOWL-001',
            'description' => 'Hand-made bowl',
            'regular_price' => 250,
            'stock_quantity' => 10,
        ], $overrides);
    }

    protected function pendingProduct(): Product
    {
        $merchant = $this->merchant();
        $category = Category::factory()->create(['merchant_id' => $merchant->id]);

        return Product::factory()->create([
            'merchant_id' => $merchant->id,
            'category_id' => $category->id,
            'status' => ProductStatus::PendingApproval,
            'submitted_at' => now()->subMinute(),
            'stock_quantity' => 10,
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function approvedProduct(Merchant $merchant, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'merchant_id' => $merchant->id,
            'status' => ProductStatus::Active,
            'approved_at' => now()->subDay(),
            'stock_quantity' => 10,
            'images' => null,
        ], $attributes))->fresh();
    }

    protected function merchant(): Merchant
    {
        return Merchant::factory()->create(['status' => MerchantStatus::Verified]);
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
