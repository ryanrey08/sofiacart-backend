<?php

namespace Tests\Feature;

use App\Admin\AdminRoleRegistry;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\AdminRole;
use App\Models\Merchant;
use App\Models\User;
use App\Notifications\AccountPasswordChanged;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_view_their_own_account_without_sensitive_fields(): void
    {
        $user = $this->merchantUser(['name' => 'Maria Dela Cruz', 'phone' => '09171234567']);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Maria Dela Cruz')
            ->assertJsonPath('data.phone', '09171234567')
            ->assertJsonPath('data.role', 'merchant')
            ->assertJsonPath('data.merchant.id', $user->merchant->id)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_merchant_can_update_allowed_personal_fields(): void
    {
        $user = $this->merchantUser(['name' => 'Maria Dela Cruz', 'phone' => '09171234567']);
        $storeName = $user->merchant->store_name;
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', ['name' => 'Maria Santos', 'phone' => '+639181112222'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Maria Santos')
            ->assertJsonPath('data.phone', '+639181112222')
            ->assertJsonMissingPath('data.password');

        $fresh = $user->fresh();
        $this->assertSame('Maria Santos', $fresh->name);
        $this->assertSame('+639181112222', $fresh->phone);
        // The merchant (store) record is untouched and not duplicated.
        $this->assertSame(1, Merchant::query()->count());
        $this->assertSame($storeName, $fresh->merchant->store_name);

        $log = AdminAuditLog::query()->where('action', 'merchant.account.updated')->sole();
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame(['fields' => ['name', 'phone']], $log->metadata);

        // Resending the same values changes nothing and writes no log.
        $this->patchJson('/api/auth/me', ['name' => 'Maria Santos'])->assertOk();
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'merchant.account.updated')->count());

        $this->patchJson('/api/auth/me', ['name' => '', 'phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone']);
    }

    public function test_changing_email_requires_the_current_password_and_a_unique_address(): void
    {
        $user = $this->merchantUser(['email' => 'maria@example.com', 'password' => 'password']);
        $this->merchantUser(['email' => 'taken@example.com']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', ['email' => 'maria.new@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/auth/me', ['email' => 'maria.new@example.com', 'current_password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');
        $this->patchJson('/api/auth/me', ['email' => 'taken@example.com', 'current_password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
        $this->assertSame('maria@example.com', $user->fresh()->email);

        // Same address with different casing is not a change, so no password is needed.
        $this->patchJson('/api/auth/me', ['email' => 'MARIA@example.com', 'name' => 'Maria'])->assertOk();

        $this->patchJson('/api/auth/me', ['email' => 'maria.new@example.com', 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', 'maria.new@example.com');
        $this->assertNull($user->fresh()->email_verified_at);

        $this->postJson('/api/auth/login', ['email' => 'maria.new@example.com', 'password' => 'password'])->assertOk();
    }

    public function test_protected_fields_and_other_accounts_cannot_be_changed(): void
    {
        $user = $this->merchantUser(['name' => 'Owner']);
        $other = $this->merchantUser(['name' => 'Other Merchant']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', [
            'name' => 'Hijack',
            'id' => $other->id,
            'user_id' => $other->id,
            'role' => 'admin',
            'is_active' => false,
            'password' => 'new-password-123',
            'status' => 'verified',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id', 'user_id', 'role', 'is_active', 'password', 'status']);

        $fresh = $user->fresh();
        $this->assertSame('Owner', $fresh->name);
        $this->assertSame(UserRole::Merchant, $fresh->role);
        $this->assertTrue(Hash::check('password', $fresh->password));
        $this->assertSame('Other Merchant', $other->fresh()->name);

        // Updates only ever apply to the authenticated account.
        $this->patchJson('/api/auth/me', ['name' => 'Owner Renamed'])->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertSame('Other Merchant', $other->fresh()->name);
    }

    public function test_password_change_rejects_invalid_requests_without_changing_the_password(): void
    {
        $user = $this->merchantUser(['password' => 'password']);
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/password', ['password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
        $this->putJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');
        $this->putJson('/api/auth/password', ['current_password' => 'password', 'password' => 'NewPassword123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password', 'password_confirmation']);
        $this->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'NewPassword123',
            'password_confirmation' => 'Different123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The new password confirmation does not match.');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_new_password_must_meet_the_password_rules(): void
    {
        $user = $this->merchantUser(['password' => 'password']);
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
        $this->putJson('/api/auth/password', ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The new password must be different from your current password.');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertFalse(AdminAuditLog::query()->where('action', 'merchant.account.password_changed')->exists());
    }

    public function test_successful_password_change_is_hashed_revokes_other_sessions_and_replaces_the_old_password(): void
    {
        $user = $this->merchantUser(['email' => 'maria@example.com', 'password' => 'password']);
        $current = $this->login('maria@example.com', 'password');
        $otherSession = $this->login('maria@example.com', 'password');
        $this->assertSame(2, $user->tokens()->count());

        $response = $this->withToken($current)->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertStringNotContainsString('NewPassword123', $response->getContent());
        $this->assertStringNotContainsString('$2y$', $response->getContent());

        $stored = $user->fresh()->password;
        $this->assertNotSame('NewPassword123', $stored);
        $this->assertTrue(Hash::isHashed($stored));
        $this->assertTrue(Hash::check('NewPassword123', $stored));

        // The session that made the change stays signed in; the other one is revoked.
        $this->assertSame(1, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherSession)->getJson('/api/auth/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'maria@example.com', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
        $this->postJson('/api/auth/login', ['email' => 'maria@example.com', 'password' => 'NewPassword123'])->assertOk();

        $log = AdminAuditLog::query()->where('action', 'merchant.account.password_changed')->sole();
        $this->assertSame(['other_sessions_revoked' => 1], $log->metadata);
        $this->assertTrue($user->notifications()->where('type', AccountPasswordChanged::class)->exists());
    }

    public function test_password_changes_are_rate_limited(): void
    {
        $user = $this->merchantUser(['password' => 'password']);
        Sanctum::actingAs($user);
        $attempt = ['current_password' => 'wrong-password', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'];

        for ($i = 0; $i < 5; $i++) {
            $this->putJson('/api/auth/password', $attempt)->assertStatus(422);
        }

        $this->putJson('/api/auth/password', $attempt)->assertStatus(429);
    }

    public function test_admin_accounts_cannot_use_the_merchant_account_endpoints(): void
    {
        $this->seed(AdminAuthorizationSeeder::class);
        $admin = User::factory()->admin()->create(['name' => 'Admin One', 'password' => 'password']);
        $admin->adminRoles()->sync([AdminRole::query()->where('slug', AdminRoleRegistry::SUPER_ADMIN)->firstOrFail()->id]);
        Sanctum::actingAs($admin, ['admin'], 'sanctum');

        $this->patchJson('/api/auth/me', ['name' => 'Renamed'])->assertForbidden();
        $this->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertForbidden();

        $this->assertSame('Admin One', $admin->fresh()->name);
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
        // The existing admin user management API keeps working.
        $this->getJson('/api/admin/users')->assertOk();
    }

    protected function merchantUser(array $attributes = []): User
    {
        $merchant = Merchant::factory()->create();
        $user = $merchant->user;
        $user->update($attributes);

        return $user->fresh(['merchant']);
    }

    protected function login(string $email, string $password): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()
            ->json('token');
    }
}
