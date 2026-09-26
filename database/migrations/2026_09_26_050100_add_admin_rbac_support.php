<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
            $table->index(['role', 'is_active']);
        });

        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('admin_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('group')->index();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('admin_role_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['admin_role_id', 'user_id']);
        });

        Schema::create('admin_permission_role', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->foreignId('admin_permission_id')->constrained('admin_permissions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['admin_role_id', 'admin_permission_id']);
        });

        Schema::create('admin_permission_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_permission_id')->constrained('admin_permissions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['admin_permission_id', 'user_id']);
        });

        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->string('description')->nullable();
            $table->nullableMorphs('subject');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('admin_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_settings');
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('admin_permission_user');
        Schema::dropIfExists('admin_permission_role');
        Schema::dropIfExists('admin_role_user');
        Schema::dropIfExists('admin_permissions');
        Schema::dropIfExists('admin_roles');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'is_active']);
            $table->dropColumn(['is_active', 'last_login_at']);
        });
    }
};
