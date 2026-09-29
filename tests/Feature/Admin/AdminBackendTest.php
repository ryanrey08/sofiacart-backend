<?php

namespace Tests\Feature\Admin;

use App\Admin\AdminPermissionRegistry;
use App\Admin\AdminRoleRegistry;
use App\Enums\MerchantStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\AdminAuditLog;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminSetting;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
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

        Sanctum::actingAs($admin, ['admin'], 'sanctum');
        $this->getJson('/api/admin/dashboard')->assertOk();
    }

    public function test_non_admin_scoped_token_cannot_access_admin_routes(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        Sanctum::actingAs($admin, ['storefront'], 'sanctum');

        $this->getJson('/api/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'An admin-scoped API token is required.');
    }

    public function test_super_admin_can_create_admin_user_and_email_a_password_setup_link(): void
    {
        Notification::fake();
        $actor = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $role = AdminRole::where('slug', AdminRoleRegistry::ADMIN)->firstOrFail();

        Sanctum::actingAs($actor, ['admin'], 'sanctum');

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Ops Admin',
            'email' => 'ops-admin@example.com',
            'phone' => '09171234567',
            'role_ids' => [$role->id],
        ])->assertCreated()
            ->assertJsonPath('data.email', 'ops-admin@example.com')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.admin_roles.0.slug', AdminRoleRegistry::ADMIN)
            ->assertJsonStructure(['password_setup' => ['email', 'expires_in_minutes']]);

        $this->assertArrayNotHasKey('token', $response->json('password_setup'));
        $newAdmin = User::query()->where('email', 'ops-admin@example.com')->firstOrFail();
        Notification::assertSentTo($newAdmin, ResetPassword::class);

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

        Sanctum::actingAs($actor, ['admin'], 'sanctum');

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

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->patchJson("/api/admin/users/{$admin->id}", [
            'is_active' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('user');
    }

    public function test_admin_can_list_and_revoke_sessions(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $firstToken = $admin->createToken('admin:first-device', ['admin'])->accessToken;
        $secondToken = $admin->createToken('admin:second-device', ['admin'])->accessToken;
        $admin->createToken('storefront-device', ['storefront']);

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
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'storefront-device']);
    }

    public function test_admin_can_revoke_all_sessions(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $admin->createToken('admin:first-device', ['admin']);
        $admin->createToken('admin:second-device', ['admin']);
        $admin->createToken('storefront-device', ['storefront']);

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->postJson('/api/admin/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('message', 'All admin sessions revoked successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'storefront-device']);
    }

    public function test_admin_can_revoke_the_current_session(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $token = $admin->createToken('admin:current-device', ['admin']);
        $tokenModel = $token->accessToken;

        $this->withToken($token->plainTextToken)
            ->deleteJson("/api/admin/auth/sessions/{$tokenModel->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Admin session revoked successfully.');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenModel->id]);
        app('auth')->forgetGuards();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/admin/dashboard')
            ->assertUnauthorized();
    }

    public function test_logout_all_revokes_the_current_authenticated_session(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $token = $admin->createToken('admin:current-device', ['admin']);
        $admin->createToken('admin:second-device', ['admin']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/admin/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('message', 'All admin sessions revoked successfully.');

        app('auth')->forgetGuards();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/admin/dashboard')
            ->assertUnauthorized();
    }

    public function test_deactivating_admin_revokes_their_tokens(): void
    {
        $superAdmin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $otherSuperAdmin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $otherSuperAdmin->createToken('target-device', ['admin']);

        Sanctum::actingAs($superAdmin, ['admin'], 'sanctum');

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

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->patchJson("/api/admin/merchants/{$merchant->id}/status", [
            'status' => MerchantStatus::Verified->value,
            'reason' => 'Compliance approved.',
        ])->assertOk()
            ->assertJsonPath('data.status', MerchantStatus::Verified->value);

        $this->getJson("/api/admin/merchants/{$merchant->id}/onboarding-history")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'admin.merchants.status');
    }

    public function test_dashboard_validates_date_filters(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->getJson('/api/admin/dashboard?date_from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_from');

        $this->getJson('/api/admin/dashboard?date_from=2026-01-02&date_to=2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_to');
    }

    public function test_platform_reports_validate_query_parameters(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->getJson('/api/admin/reports/platform?type=unknown')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->getJson('/api/admin/reports/platform?per_page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');

        $this->get('/api/admin/reports/export?type=unknown')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_platform_report_export_streams_the_selected_report_columns(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $merchant = Merchant::factory()->create();
        Payment::create([
            'merchant_id' => $merchant->id,
            'reference' => 'PAY-REPORT-EXPORT-001',
            'gateway' => 'gcash',
            'status' => PaymentStatus::Completed,
            'amount' => 23,
        ]);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $response = $this->get('/api/admin/reports/export?type=payment_status')->assertOk();
        $rows = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));

        $this->assertSame(['status', 'payments_count', 'total_amount'], $rows[0]);
        $this->assertSame('completed', $rows[1][0]);
        $this->assertSame('1', $rows[1][1]);
        $this->assertEquals(23, (float) $rows[1][2]);
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
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

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

    public function test_payment_can_receive_multiple_partial_refunds(): void
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
            'reference' => 'PAY-REFUND-PARTIAL-001',
            'gateway' => 'gcash',
            'status' => PaymentStatus::Completed,
            'amount' => 100,
        ]);

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->postJson('/api/admin/refunds', [
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'reference' => 'REF-PARTIAL-001',
            'amount' => 40,
            'status' => RefundStatus::Processed->value,
        ])->assertCreated();

        $this->postJson('/api/admin/refunds', [
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'reference' => 'REF-PARTIAL-002',
            'amount' => 60,
            'status' => RefundStatus::Processed->value,
        ])->assertCreated();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Refunded->value,
        ]);
    }

    public function test_merchant_billing_counts_only_processed_refunds(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $merchant = Merchant::factory()->create();
        $payment = Payment::create([
            'merchant_id' => $merchant->id,
            'reference' => 'PAY-BILLING-001',
            'gateway' => 'gcash',
            'status' => PaymentStatus::Completed,
            'amount' => 100,
        ]);
        Payment::create([
            'merchant_id' => $merchant->id,
            'reference' => 'PAY-BILLING-PENDING',
            'gateway' => 'gcash',
            'status' => PaymentStatus::Pending,
            'amount' => 30,
        ]);
        Payment::create([
            'merchant_id' => $merchant->id,
            'reference' => 'PAY-BILLING-FAILED',
            'gateway' => 'gcash',
            'status' => PaymentStatus::Failed,
            'amount' => 5,
        ]);
        Refund::create([
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'reference' => 'REF-BILLING-PROCESSED',
            'amount' => 10,
            'status' => RefundStatus::Processed,
        ]);
        Refund::create([
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'reference' => 'REF-BILLING-PENDING',
            'amount' => 40,
            'status' => RefundStatus::Pending,
        ]);

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->getJson("/api/admin/merchants/{$merchant->id}/billing")
            ->assertOk()
            ->assertJsonPath('data.payments_total', '100.00')
            ->assertJsonPath('data.payments_count', 1)
            ->assertJsonPath('data.refunds_total', '10.00')
            ->assertJsonPath('data.refunds_count', 1)
            ->assertJsonPath('data.net_total', '90.00');

        $dashboard = $this->getJson('/api/admin/dashboard')->assertOk();
        $this->assertEquals(100, (float) $dashboard->json('data.metrics.payments_collected'));

        $merchantList = $this->getJson('/api/admin/merchants')->assertOk();
        $this->assertEquals(100, (float) $merchantList->json('data.0.payments_sum_amount'));

        $merchantDetails = $this->getJson("/api/admin/merchants/{$merchant->id}")->assertOk();
        $this->assertEquals(100, (float) $merchantDetails->json('data.payments_sum_amount'));
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

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->patchJson("/api/admin/refunds/{$refund->id}", [
            'amount' => 50,
        ])->assertOk()
            ->assertJsonPath('data.amount', '50.00');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PartiallyRefunded->value,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => OrderPaymentStatus::PartiallyRefunded->value,
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

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->patchJson("/api/admin/roles/{$systemRole->id}", [
            'name' => 'Changed',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->deleteJson("/api/admin/permissions/{$systemPermission->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('permission');
    }

    public function test_system_log_page_size_is_capped(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->getJson('/api/admin/logs?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/admin/merchants?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_admin_user_can_be_created_without_assigning_direct_permissions(): void
    {
        $usersOnlyRole = AdminRole::query()->create([
            'slug' => 'users-only',
            'name' => 'Users Only',
            'is_system' => false,
        ]);
        $usersManage = AdminPermission::query()->firstWhere('name', AdminPermissionRegistry::USERS_MANAGE);
        $usersOnlyRole->permissions()->sync([$usersManage->id]);

        $admin = User::factory()->admin()->create([
            'email' => fake()->unique()->safeEmail(),
        ]);
        $admin->adminRoles()->sync([$usersOnlyRole->id]);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->postJson('/api/admin/users', [
            'name' => 'New Operator',
            'email' => 'new-operator@example.test',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'new-operator@example.test')
            ->assertJsonPath('data.admin_permissions', []);

        $this->postJson('/api/admin/users', [
            'name' => 'Role Operator',
            'email' => 'role-operator@example.test',
            'role_ids' => [$usersOnlyRole->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    public function test_admin_cannot_assign_a_role_with_unheld_permissions(): void
    {
        $usersOnlyRole = AdminRole::query()->create([
            'slug' => 'limited-role-assigner',
            'name' => 'Limited Role Assigner',
            'is_system' => false,
        ]);
        $usersManage = AdminPermission::query()->firstWhere('name', AdminPermissionRegistry::USERS_MANAGE);
        $assignRoles = AdminPermission::query()->firstWhere('name', AdminPermissionRegistry::USERS_ASSIGN_ROLES);
        $usersOnlyRole->permissions()->sync([$usersManage->id]);

        $privilegedRole = AdminRole::query()->create([
            'slug' => 'permission-escalation',
            'name' => 'Permission Escalation',
            'is_system' => false,
        ]);
        $permissionsManage = AdminPermission::query()->firstWhere('name', AdminPermissionRegistry::PERMISSIONS_MANAGE);
        $privilegedRole->permissions()->sync([$permissionsManage->id]);

        $admin = User::factory()->admin()->create([
            'email' => fake()->unique()->safeEmail(),
        ]);
        $admin->adminRoles()->sync([$usersOnlyRole->id]);
        $admin->adminPermissions()->sync([$assignRoles->id]);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->postJson('/api/admin/users', [
            'name' => 'Escalated Operator',
            'email' => 'escalated-operator@example.test',
            'role_ids' => [$privilegedRole->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    public function test_settings_updates_preserve_omitted_values_and_descriptions(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $setting = AdminSetting::query()->create([
            'key' => 'storefront.name',
            'value' => 'SofiaCart',
            'description' => 'The public store name.',
            'is_secret' => false,
        ]);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->putJson('/api/admin/settings', [
            'settings' => [['key' => $setting->key]],
        ])->assertOk();

        $this->assertSame('SofiaCart', $setting->fresh()->value);
        $this->assertSame('The public store name.', $setting->fresh()->description);

        $this->putJson('/api/admin/settings', [
            'settings' => [[
                'key' => $setting->key,
                'description' => 'Updated description.',
            ]],
        ])->assertOk();

        $this->assertSame('SofiaCart', $setting->fresh()->value);
        $this->assertSame('Updated description.', $setting->fresh()->description);
    }

    public function test_secret_settings_remain_masked_and_preserve_existing_values_when_only_metadata_changes(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $setting = AdminSetting::query()->create([
            'key' => 'payments.webhook_secret',
            'value' => ['secret' => 'top-secret-value'],
            'description' => 'Original description',
            'is_secret' => true,
        ]);

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'payments.webhook_secret',
                'is_secret' => true,
            ])
            ->assertJsonFragment([
                'configured' => true,
            ])
            ->assertJsonMissing([
                'value' => ['secret' => 'top-secret-value'],
            ]);

        $this->putJson('/api/admin/settings', [
            'settings' => [[
                'key' => $setting->key,
                'description' => 'Updated description only',
            ]],
        ])->assertOk()
            ->assertJsonFragment([
                'key' => 'payments.webhook_secret',
                'description' => 'Updated description only',
            ])
            ->assertJsonFragment([
                'configured' => true,
            ])
            ->assertJsonMissing([
                'value' => ['secret' => 'top-secret-value'],
            ]);

        $this->assertDatabaseHas('admin_settings', [
            'id' => $setting->id,
            'description' => 'Updated description only',
            'is_secret' => true,
        ]);
        $this->assertSame(['secret' => 'top-secret-value'], $setting->fresh()->value);
    }

    public function test_provisioning_requires_force_for_existing_accounts_and_revokes_their_tokens(): void
    {
        $merchant = Merchant::factory()->create();
        $user = $merchant->user;
        $token = $user->createToken('storefront-device', ['storefront'])->accessToken;

        $result = Artisan::call('admin:provision-super-admin', [
            'email' => $user->email,
            'name' => 'Promoted Super Admin',
        ]);

        $this->assertSame(1, $result);
        $this->assertFalse($user->fresh()->isActiveAdmin());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);

        $result = Artisan::call('admin:provision-super-admin', [
            'email' => $user->email,
            'name' => 'Promoted Super Admin',
            '--force' => true,
        ]);

        $this->assertSame(0, $result);
        $this->assertTrue($user->fresh()->hasAdminRole(AdminRoleRegistry::SUPER_ADMIN));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_system_logs_redact_sensitive_metadata_fields(): void
    {
        $admin = $this->createAdminWithRole(AdminRoleRegistry::SUPER_ADMIN);
        $log = AdminAuditLog::query()->create([
            'actor_id' => $admin->id,
            'action' => 'admin.settings.updated',
            'description' => 'Updated settings.',
            'metadata' => [
                'token' => 'plain-token',
                'api_key' => 'plain-api-key',
                'nested' => [
                    'password' => 'plain-password',
                    'safe' => 'kept',
                ],
            ],
            'created_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->getJson("/api/admin/logs/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.metadata.token', '[REDACTED]')
            ->assertJsonPath('data.metadata.api_key', '[REDACTED]')
            ->assertJsonPath('data.metadata.nested.password', '[REDACTED]')
            ->assertJsonPath('data.metadata.nested.safe', 'kept');
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
