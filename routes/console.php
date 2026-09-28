<?php

use App\Admin\AdminRoleRegistry;
use App\Enums\UserRole;
use App\Models\AdminRole;
use App\Models\User;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:provision-super-admin {email} {name} {--phone=} {--force}', function (): void {
    $this->call(AdminAuthorizationSeeder::class);

    $existingSuperAdmin = User::query()
        ->where('role', UserRole::Admin)
        ->where('is_active', true)
        ->whereHas('adminRoles', fn ($query) => $query->where('slug', AdminRoleRegistry::SUPER_ADMIN))
        ->exists();

    if ($existingSuperAdmin && ! $this->option('force')) {
        $this->error('An active Super Admin already exists. Re-run with --force only if you intend to provision another one.');

        return;
    }

    $user = User::query()->firstOrCreate(
        ['email' => $this->argument('email')],
        [
            'role' => UserRole::Admin,
            'is_active' => true,
            'name' => $this->argument('name'),
            'phone' => $this->option('phone') ?: null,
            'email_verified_at' => now(),
            'password' => Hash::make(Str::password(32)),
        ],
    );

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

    $token = Password::broker('users')->createToken($user);

    $this->info('Super Admin provisioned successfully.');
    $this->line('Email: '.$user->email);
    $this->line('Password setup token: '.$token);
    $this->line('Reset via POST /api/admin/auth/reset-password with email, token, password, and password_confirmation.');
})->purpose('Provision a Super Admin without seeding a permanent password');
