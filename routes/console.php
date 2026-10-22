<?php

use App\Admin\AdminPermissionRegistry;
use App\Admin\AdminRoleRegistry;
use App\Enums\LegalDocumentAudience;
use App\Enums\LegalDocumentType;
use App\Enums\UserRole;
use App\Models\AdminRole;
use App\Models\User;
use App\Services\LegalDocumentService;
use App\Services\PaymentService;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\UploadedFile;
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

Artisan::command(
    'legal-documents:import {audience : buyer or merchant} {type : terms or privacy} {path : PDF or .docx file} {--admin= : Email of the admin recorded as uploader} {--label= : Version label printed in the document} {--effective-date= : YYYY-MM-DD} {--publish}',
    function (LegalDocumentService $documents): int {
        $audience = LegalDocumentAudience::tryFrom((string) $this->argument('audience'));
        $type = LegalDocumentType::tryFrom((string) $this->argument('type'));
        $path = (string) $this->argument('path');

        if (! $audience || ! $type) {
            $this->error('Audience must be buyer or merchant, and type must be terms or privacy.');

            return 1;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return 1;
        }

        $admin = User::query()->where('email', (string) $this->option('admin'))->first();
        if (! $admin?->hasAdminPermission(AdminPermissionRegistry::LEGAL_MANAGE)) {
            $this->error('--admin must be the email of an active admin with the legal.manage permission.');

            return 1;
        }

        $effectiveDate = $this->option('effective-date');
        if ($effectiveDate !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $effectiveDate)) {
            $this->error('--effective-date must be YYYY-MM-DD.');

            return 1;
        }

        // Same validation as an upload through the admin panel; the file is copied, never modified.
        $file = new UploadedFile($path, basename($path), null, null, true);
        if ($documents->detectFormat($file) === null) {
            $this->error('The file is not a valid PDF or Word (.docx) document.');

            return 1;
        }

        if ($file->getSize() > config('legal_documents.max_kilobytes') * 1024) {
            $this->error('The file is larger than the allowed upload size.');

            return 1;
        }

        $document = $documents->upload($audience, $type, $file, [
            'version_label' => $this->option('label') ?: null,
            'effective_date' => $effectiveDate ?: null,
            'notes' => 'Imported from '.basename($path).' with legal-documents:import.',
        ], $admin);

        $this->info("Uploaded {$audience->label()} {$type->label()} version {$document->version} (sha256 {$document->checksum}).");

        if ($this->option('publish')) {
            $documents->publish($document, $admin);
            $this->info("Published version {$document->version}.");
        }

        return 0;
    },
)->purpose('Import a legal document file as a new version (optionally publishing it)');

Artisan::command('payments:expire', function (PaymentService $payments): int {
    $this->info("Expired {$payments->expirePendingPayments()} pending payment(s).");

    return 0;
})->purpose('Mark pending payments past their expiry time as expired');

Schedule::command('payments:expire')->everyFiveMinutes()->withoutOverlapping();
