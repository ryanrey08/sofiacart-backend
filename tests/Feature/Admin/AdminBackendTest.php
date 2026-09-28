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
use App\Models\Refund;
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

    public function test_admin_can_list_and_revoke_sessions(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $firstToken = $admin->createToken('first-device', ['admin'])->accessToken;
        $secondToken = $admin->createToken('second-device', ['admin'])->accessToken;

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->getJson('/api/admin/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'first-device'])
            ->assertJsonFragment(['name' => 'second-device']);

        $this->deleteJson("/api/admin/auth/sessions/{$firstToken->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Admin session revoked successfully.');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $secondToken->id]);
    }

    public function test_admin_can_revoke_all_sessions(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $admin->createToken('first-device', ['admin']);
        $admin->createToken('second-device', ['admin']);

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->postJson('/api/admin/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('message', 'All admin sessions revoked successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deactivating_admin_revokes_their_tokens(): void
    {
        $superAdmin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $otherSuperAdmin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $otherSuperAdmin->createToken('target-device', ['admin']);

        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/admin/users/{$otherSuperAdmin->id}", [
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $otherSuperAdmin->id,
            'tokenable_type' => User::class,
        ]);
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

    public function test_reducing_a_processed_refund_restores_payment_and_order_statuses(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $merchant = Merchant::factory()->create();
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Refunded,
            'total_amount' => 100,
        ]);
        $payment = Payment::create([
            'merchant_id' => $merchant->id,
            'order_id' => $order->id,
            'reference' => 'PAY-REFUND-RESTORE-001',
            'gateway' => 'gcash',
            'status' => PaymentStatus::Refunded,
            'amount' => 100,
        ]);

        $refund = Refund::create([
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'reference' => 'REF-RESTORE-001',
            'amount' => 100,
            'status' => RefundStatus::Processed,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/refunds/{$refund->id}", [
            'amount' => 50,
        ])->assertOk()
            ->assertJsonPath('data.amount', '50.00');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Completed->value,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => OrderPaymentStatus::Paid->value,
        ]);
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

    public function test_system_roles_and_permissions_cannot_be_modified(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $systemRole = AdminRole::where('slug', AdminRoleRegistry::ADMIN)->firstOrFail();
        $systemPermission = AdminPermission::where('name', AdminPermissionRegistry::DASHBOARD_VIEW)->firstOrFail();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/roles/{$systemRole->id}", [
            'name' => 'Changed',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->deleteJson("/api/admin/permissions/{$systemPermission->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('permission');
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
