<?php

namespace Tests\Feature\Admin;

use App\Admin\AdminRoleRegistry;
use App\Enums\MerchantStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\AdminAuditLog;
use App\Models\AdminRole;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\User;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminMerchantOversightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAuthorizationSeeder::class);
    }

    public function test_super_admin_can_list_filter_sort_and_summarize_merchants(): void
    {
        $this->actingAsAdmin();
        Merchant::factory()->create(['store_name' => 'Zeta Goods', 'status' => MerchantStatus::Verified]);
        Merchant::factory()->create(['store_name' => 'Alpha Mart', 'status' => MerchantStatus::Pending]);
        Merchant::factory()->create(['store_name' => 'Beta Shop', 'status' => MerchantStatus::Rejected]);

        $this->getJson('/api/admin/merchants?sort=name_asc')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.store_name', 'Alpha Mart')
            ->assertJsonPath('data.2.store_name', 'Zeta Goods');

        $this->getJson('/api/admin/merchants?statuses[]=pending&statuses[]=rejected&sort=name_desc')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.store_name', 'Beta Shop');

        $this->getJson('/api/admin/merchants?sort=unknown')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');

        $this->getJson('/api/admin/merchants/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.by_status.verified', 1)
            ->assertJsonPath('data.by_status.pending', 1)
            ->assertJsonPath('data.by_status.rejected', 1)
            ->assertJsonPath('data.by_status.suspended', 0);
    }

    public function test_super_admin_can_approve_a_pending_merchant_and_the_decision_is_persisted_and_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Pending]);

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['status' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $this->assertSame(MerchantStatus::Verified, $merchant->fresh()->status);

        $log = AdminAuditLog::query()->where('action', 'admin.merchants.status')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($merchant->getMorphClass(), $log->subject_type);
        $this->assertSame($merchant->id, (int) $log->subject_id);
        $this->assertSame('Merchant approved.', $log->description);
        $this->assertSame(['status' => 'verified', 'previous_status' => 'pending', 'reason' => null], $log->metadata);
        $this->assertNotNull($log->created_at);

        $this->getJson("/api/admin/merchants/{$merchant->id}/onboarding-history")
            ->assertOk()
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('data.0.metadata.previous_status', 'pending');
    }

    public function test_rejection_requires_a_reason_which_is_recorded(): void
    {
        $this->actingAsAdmin();
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Pending]);

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['status' => 'rejected'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
        $this->assertSame(MerchantStatus::Pending, $merchant->fresh()->status);

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", [
            'status' => 'rejected',
            'reason' => 'Business permit is expired.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(MerchantStatus::Rejected, $merchant->fresh()->status);
        $this->assertSame(
            'Business permit is expired.',
            AdminAuditLog::query()->where('action', 'admin.merchants.status')->sole()->metadata['reason'],
        );
    }

    public function test_repeating_the_current_decision_is_rejected_without_a_duplicate_log(): void
    {
        $this->actingAsAdmin();
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Verified]);

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['status' => 'verified'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(0, AdminAuditLog::query()->where('action', 'admin.merchants.status')->count());
    }

    public function test_merchants_and_unauthorized_admins_cannot_approve_merchants(): void
    {
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Pending]);

        Sanctum::actingAs($merchant->user, ['*'], 'sanctum');
        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['status' => 'verified'])->assertForbidden();
        $this->getJson('/api/admin/merchants')->assertForbidden();

        $viewOnlyAdmin = $this->createAdminWithRole(AdminRoleRegistry::ADMIN);
        Sanctum::actingAs($viewOnlyAdmin, ['admin'], 'sanctum');
        $this->getJson('/api/admin/merchants')->assertOk();
        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", ['status' => 'verified'])->assertForbidden();

        $this->assertSame(MerchantStatus::Pending, $merchant->fresh()->status);
        $this->assertSame(0, AdminAuditLog::query()->where('action', 'admin.merchants.status')->count());
    }

    public function test_admin_can_review_uploaded_registration_documents(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $merchant = Merchant::factory()->create(['store_banner_path' => null]);
        Storage::disk('public')->put($merchant->business_permit_path, 'permit-bytes');

        $this->getJson("/api/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->assertJsonPath('data.documents', ['business_permit', 'government_id', 'store_logo']);

        $response = $this->get("/api/admin/merchants/{$merchant->id}/documents/business_permit");
        $response->assertOk();
        $this->assertSame('permit-bytes', $response->streamedContent());

        $this->getJson("/api/admin/merchants/{$merchant->id}/documents/government_id")->assertNotFound();
        $this->getJson("/api/admin/merchants/{$merchant->id}/documents/store_banner")->assertNotFound();
        $this->getJson("/api/admin/merchants/{$merchant->id}/documents/password")->assertNotFound();
    }

    public function test_orders_and_products_are_visible_platform_wide_with_a_merchant_filter(): void
    {
        $this->actingAsAdmin();
        [$first, $second] = Merchant::factory()->count(2)->create();
        Product::factory()->create(['merchant_id' => $first->id]);
        Product::factory()->count(2)->create(['merchant_id' => $second->id]);
        Order::factory()->create(['merchant_id' => $first->id]);
        Order::factory()->count(2)->create(['merchant_id' => $second->id]);

        $this->getJson('/api/admin/products')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/admin/products?merchant_id={$first->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.merchant.store_name', $first->store_name);

        $this->getJson('/api/admin/orders')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/admin/orders?merchant_id={$second->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.merchant.id', $second->id);
    }

    public function test_inventory_is_visible_platform_wide(): void
    {
        $this->actingAsAdmin();
        [$first, $second] = Merchant::factory()->count(2)->create();
        Product::factory()->create(['merchant_id' => $first->id, 'stock_quantity' => 5]);
        Product::factory()->create(['merchant_id' => $second->id, 'stock_quantity' => 0]);

        $this->getJson('/api/admin/inventory')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/admin/inventory?merchant_id={$first->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/inventory/summary')->assertOk()->assertJsonPath('data.total_items', 2);
    }

    public function test_payments_refunds_and_returns_are_visible_platform_wide(): void
    {
        $this->actingAsAdmin();
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Paid,
            'total_amount' => 100,
        ]);
        $payment = Payment::create([
            'merchant_id' => $merchant->id,
            'order_id' => $order->id,
            'reference' => 'PAY-OVERSIGHT-1',
            'gateway' => 'gcash',
            'status' => PaymentStatus::Completed,
            'amount' => 100,
            'metadata' => ['client_secret' => 'should-not-leak'],
        ]);
        Refund::create([
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'reference' => 'REF-OVERSIGHT-1',
            'amount' => 20,
            'status' => RefundStatus::Pending,
        ]);
        ReturnRequest::create([
            'merchant_id' => $merchant->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
            'reason' => 'Damaged item',
            'amount' => 20,
        ]);
        Merchant::factory()->create();

        $this->getJson("/api/admin/payments?merchant_id={$merchant->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.merchant.store_name', $merchant->store_name)
            ->assertJsonMissing(['client_secret' => 'should-not-leak']);

        $this->getJson("/api/admin/refunds?merchant_id={$merchant->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'REF-OVERSIGHT-1');

        $this->getJson('/api/admin/return-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order.order_number', $order->order_number)
            ->assertJsonPath('data.0.merchant.id', $merchant->id);

        $this->getJson("/api/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonCount(1, 'data.refunds')
            ->assertJsonCount(1, 'data.return_requests');
    }

    public function test_customers_are_listed_platform_wide_with_masked_contact_details(): void
    {
        $this->actingAsAdmin();
        $merchant = Merchant::factory()->create();
        Customer::factory()->create(['merchant_id' => $merchant->id, 'email' => 'jane.customer@example.com', 'is_active' => true]);
        Customer::factory()->create(['merchant_id' => $merchant->id, 'is_active' => false]);

        $response = $this->getJson("/api/admin/customers?merchant_id={$merchant->id}&status=active")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.merchant.store_name', $merchant->store_name)
            ->assertJsonMissingPath('data.0.email');

        $this->assertStringNotContainsString('jane.customer@example.com', $response->getContent());
        $this->getJson('/api/admin/customers?status=blocked')->assertStatus(422);
    }

    public function test_admin_mutations_are_logged_with_the_target_entity(): void
    {
        $admin = $this->actingAsAdmin();
        $order = Order::factory()->create([
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
        ]);

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'processing'])->assertOk();

        $log = AdminAuditLog::query()->where('action', 'admin.orders.status')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($order->getMorphClass(), $log->subject_type);
        $this->assertSame($order->id, (int) $log->subject_id);
        $this->assertSame('processing', $log->metadata['fields']['status']);

        $this->getJson('/api/admin/logs?module=orders')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/logs?module=merchants')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_dashboard_reports_platform_totals_and_daily_series(): void
    {
        $this->actingAsAdmin();
        Merchant::factory()->create(['status' => MerchantStatus::Pending]);
        Merchant::factory()->create(['status' => MerchantStatus::InformationRequested]);
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Verified]);
        Order::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => Customer::factory()->create(['merchant_id' => $merchant->id])->id,
            'ordered_at' => now(),
            'total_amount' => 250,
        ]);

        $response = $this->getJson('/api/admin/dashboard?date_from='.now()->subDays(6)->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.totals.merchants', 3)
            ->assertJsonPath('data.totals.pending_onboarding', 2)
            ->assertJsonPath('data.totals.merchants_by_status.verified', 1)
            ->assertJsonPath('data.metrics.orders_count', 1)
            ->assertJsonCount(7, 'data.series');

        $today = collect($response->json('data.series'))->firstWhere('date', now()->toDateString());
        $this->assertSame(1, $today['orders_count']);
        $this->assertSame('250.00', $today['gross_sales']);
    }

    public function test_platform_reports_filter_by_merchant_and_date(): void
    {
        $this->actingAsAdmin();
        [$first, $second] = Merchant::factory()->count(2)->create();
        Order::factory()->create(['merchant_id' => $first->id, 'ordered_at' => '2026-03-10 10:00:00', 'total_amount' => 100]);
        Order::factory()->create(['merchant_id' => $first->id, 'ordered_at' => '2026-05-10 10:00:00', 'total_amount' => 300]);
        Order::factory()->create(['merchant_id' => $second->id, 'ordered_at' => '2026-03-11 10:00:00', 'total_amount' => 50]);

        $this->getJson("/api/admin/reports/platform?type=merchant_sales&merchant_id={$first->id}&date_from=2026-03-01&date_to=2026-03-31")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.orders_count', 1);

        $this->getJson('/api/admin/reports/platform?type=order_status&merchant_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('merchant_id');
    }

    protected function actingAsAdmin(string $role = AdminRoleRegistry::SUPER_ADMIN): User
    {
        $admin = $this->createAdminWithRole($role);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        return $admin;
    }

    protected function createAdminWithRole(string $roleSlug): User
    {
        $user = User::factory()->admin()->create([
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ]);

        $user->adminRoles()->sync([AdminRole::where('slug', $roleSlug)->firstOrFail()->id]);

        return $user->fresh(['adminRoles.permissions', 'adminPermissions']);
    }
}
