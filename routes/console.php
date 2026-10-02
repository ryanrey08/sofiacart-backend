<?php

use App\Admin\AdminRoleRegistry;
use App\Enums\UserRole;
use App\Models\AdminRole;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:provision-super-admin {email} {name} {--phone=} {--force} {--show-token}', function (): int {
    $this->call('db:seed', ['--class' => AdminAuthorizationSeeder::class]);

    $existingSuperAdmin = User::query()
        ->where('role', UserRole::Admin)
        ->where('is_active', true)
        ->whereHas('adminRoles', fn ($query) => $query->where('slug', AdminRoleRegistry::SUPER_ADMIN))
        ->exists();

    if ($existingSuperAdmin && ! $this->option('force')) {
        $this->error('An active Super Admin already exists. Re-run with --force only if you intend to provision another one.');

        return 1;
    }

    $user = User::query()->where('email', $this->argument('email'))->first();
    $wasSuperAdmin = $user?->isActiveAdmin()
        && $user->adminRoles()->where('slug', AdminRoleRegistry::SUPER_ADMIN)->exists();

    if ($user && ! $wasSuperAdmin && ! $this->option('force')) {
        $this->error('The email belongs to an existing account without the Super Admin role.');
        $this->line('Re-run with --force only if you intend to promote it.');

        return 1;
    }

    if (! $user) {
        $user = User::query()->create([
            'email' => $this->argument('email'),
            'role' => UserRole::Admin,
            'is_active' => true,
            'name' => $this->argument('name'),
            'phone' => $this->option('phone') ?: null,
            'email_verified_at' => now(),
            'password' => Hash::make(Str::password(32)),
        ]);
    }

    $roleId = AdminRole::query()
        ->where('slug', AdminRoleRegistry::SUPER_ADMIN)
        ->value('id');

    $user->update([
        'role' => UserRole::Admin,
        'is_active' => true,
        'name' => $this->argument('name'),
        'phone' => $this->option('phone') ?: $user->phone,
    ]);
    $user->adminRoles()->syncWithoutDetaching([$roleId]);

    if (! $wasSuperAdmin) {
        $user->tokens()->delete();
    }

    $token = Password::broker('users')->createToken($user);

    $this->info('Super Admin provisioned successfully.');
    $this->line('Email: '.$user->email);

    if ($this->option('show-token')) {
        $this->warn('Password setup token (handle securely, one-time display): '.$token);
    } else {
        $this->line('A password setup token was generated but not displayed. Re-run with --show-token only in a secure terminal if you must capture it manually.');
    }

    $this->line('Reset via POST /api/admin/auth/reset-password with email, token, password, and password_confirmation.');

    return 0;
})->purpose('Provision a Super Admin without seeding a permanent password');

Artisan::command('payments:expire', function (PaymentService $payments): int {
    $this->info("Expired {$payments->expirePendingPayments()} pending payment(s).");

    return 0;
})->purpose('Mark pending payments past their expiry time as expired');

Schedule::command('payments:expire')->everyFiveMinutes()->withoutOverlapping();
