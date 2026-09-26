<?php

namespace Tests\Feature\Admin;

use App\Admin\AdminPermissionRegistry;
use App\Admin\AdminRoleRegistry;
use App\Enums\MerchantStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAuthorizationSeeder::class);
    }

    public function test_only_active_admins_can_use_admin_auth_and_routes(): void
    {
        $merchant = Merchant::factory()->create();
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);

        $this->postJson('/api/admin/auth/login', [
            'email' => $merchant->user->email,
            'password' => 'password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonStructure(['message', 'token', 'user' => ['id', 'email', 'admin_roles']]);

        Sanctum::actingAs($merchant->user);
        $this->getJson('/api/admin/dashboard')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/dashboard')->assertOk();
    }

    public function test_super_admin_can_create_admin_user_and_receive_password_setup_token(): void
    {
        $actor = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $role = AdminRole::where('slug', AdminRoleRegistry::ADMIN)->firstOrFail();

        Sanctum::actingAs($actor);

        $this->postJson('/api/admin/users', [
            'name' => 'Ops Admin',
            'email' => 'ops-admin@example.com',
            'phone' => '09171234567',
            'role_ids' => [$role->id],
        ])->assertCreated()
            ->assertJsonPath('data.email', 'ops-admin@example.com')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.admin_roles.0.slug', AdminRoleRegistry::ADMIN)
            ->assertJsonStructure(['password_setup' => ['email', 'token', 'expires_in_minutes']]);

        $this->assertDatabaseHas('users', [
            'email' => 'ops-admin@example.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_admin_without_super_admin_assignment_permission_cannot_delegate_super_admin_role(): void
    {
        $actor = User::factory()->admin()->create();
        $actor->adminPermissions()->sync(
            AdminPermission::whereIn('name', [
                AdminPermissionRegistry::USERS_MANAGE,
                AdminPermissionRegistry::USERS_ASSIGN_ROLES,
                AdminPermissionRegistry::PERMISSIONS_MANAGE,
            ])->pluck('id')->all()
        );

        $superAdminRole = AdminRole::where('slug', AdminRoleRegistry::SUPER_ADMIN)->firstOrFail();

        Sanctum::actingAs($actor);

        $this->postJson('/api/admin/users', [
            'name' => 'Blocked Admin',
            'email' => 'blocked-admin@example.com',
            'role_ids' => [$superAdminRole->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    public function test_last_super_admin_cannot_be_deactivated(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$admin->id}", [
            'is_active' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('user');
    }

    public function test_admin_can_update_merchant_status_and_review_audit_history(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Pending]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", [
            'status' => MerchantStatus::Verified->value,
            'reason' => 'Compliance approved.',
        ])->assertOk()
            ->assertJsonPath('data.status', MerchantStatus::Verified->value);

        $this->getJson("/api/admin/merchants/{$merchant->id}/onboarding-history")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'admin.merchants.status');
    }

    public function test_refunds_cannot_exceed_remaining_payment_balance(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $merchant = Merchant::factory()->create();
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Paid,
            'total_amount' => 100,
        ]);
        $payment = Payment::create([
            'merchant_id' => $merchant->id,
            'order_id' => $order->id,
            'reference' => 'PAY-REFUND-001',
            'gateway' => 'gcash',
            'status' => PaymentStatus::Completed,
            'amount' => 100,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/refunds', [
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'reference' => 'REF-001',
            'amount' => 120,
            'status' => RefundStatus::Pending->value,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_orders_cannot_skip_required_status_transitions(): void
    {
        $merchant = Merchant::factory()->create();
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => OrderStatus::Pending,
        ]);

        Sanctum::actingAs($merchant->user);

        $this->patchJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Completed->value,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    protected function createAdminWithRole(string $roleSlug): User
    {
        $user = User::factory()->admin()->create([
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ]);

        $role = AdminRole::where('slug', $roleSlug)->firstOrFail();
        $user->adminRoles()->sync([$role->id]);

        return $user->fresh(['adminRoles.permissions', 'adminPermissions']);
    }
}
