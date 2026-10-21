<?php

namespace Database\Seeders;

use App\Admin\AdminRoleRegistry;
use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use App\Models\AdminRole;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local test accounts for walking through the product approval workflow
 * (see sofiacart-frontend/SOFIACART_PRODUCT_APPROVAL_STATUS.md). Idempotent; refuses to run in
 * production. Run with:
 *
 *   php artisan db:seed --class=ProductApprovalTestAccountsSeeder
 *
 * These are test-only credentials for the local stack — never reuse them anywhere else.
 */
class ProductApprovalTestAccountsSeeder extends Seeder
{
    public const PASSWORD = 'SofiaTest-Approval-2026';

    // Super Admin: sign in at /admin/login.
    public const ADMIN_EMAIL = 'approval-admin@sofiacart.test';

    // Verified merchant with a verified email: sign in at /login.
    public const MERCHANT_EMAIL = 'approval-merchant@sofiacart.test';

    public const STORE_SLUG = 'approval-test-store';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ProductApprovalTestAccountsSeeder must not run in production.');
        }

        $this->call(AdminAuthorizationSeeder::class);

        $admin = User::query()->updateOrCreate(['email' => self::ADMIN_EMAIL], [
            'name' => 'Approval Test Super Admin',
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);
        $admin->adminRoles()->sync([AdminRole::query()->where('slug', AdminRoleRegistry::SUPER_ADMIN)->firstOrFail()->id]);

        $merchantUser = User::query()->updateOrCreate(['email' => self::MERCHANT_EMAIL], [
            'name' => 'Approval Test Merchant',
            'role' => UserRole::Merchant,
            'is_active' => true,
            'phone' => '09170000001',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $merchant = Merchant::query()->where('user_id', $merchantUser->id)->first()
            ?? Merchant::factory()->create([
                'user_id' => $merchantUser->id,
                'store_name' => 'Approval Test Store',
                'store_slug' => self::STORE_SLUG,
                'business_name' => 'Approval Test Store',
                'contact_email' => self::MERCHANT_EMAIL,
                'owner_email' => self::MERCHANT_EMAIL,
            ]);
        $merchant->update(['status' => MerchantStatus::Verified]);

        Category::query()->firstOrCreate(
            ['merchant_id' => $merchant->id, 'slug' => 'approval-test-category'],
            ['name' => 'Approval Test Category', 'description' => 'Category for approval workflow testing.', 'is_active' => true],
        );
    }
}
