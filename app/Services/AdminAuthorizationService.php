<?php

namespace App\Services;

use App\Admin\AdminPermissionRegistry;
use App\Admin\AdminRoleRegistry;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdminAuthorizationService
{
    public function ensureAssignableRoles(User $actor, array $roleIds, ?User $target = null): Collection
    {
        $roles = AdminRole::with('permissions')->whereIn('id', $roleIds)->get()->keyBy('id');

        if (count($roleIds) !== $roles->count()) {
            throw ValidationException::withMessages([
                'roles' => ['One or more selected roles could not be found.'],
            ]);
        }

        if ($target && $actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => ['You cannot change your own admin role assignments.'],
            ]);
        }

        if ($roleIds !== [] && ! $actor->hasAdminPermission(AdminPermissionRegistry::USERS_ASSIGN_ROLES)) {
            throw ValidationException::withMessages([
                'roles' => ['You are not allowed to assign admin roles.'],
            ]);
        }

        foreach ($roles as $role) {
            if ($role->slug === AdminRoleRegistry::SUPER_ADMIN
                && ! $actor->hasAdminPermission(AdminPermissionRegistry::USERS_ASSIGN_SUPER_ADMIN)) {
                throw ValidationException::withMessages([
                    'roles' => ['You are not allowed to assign the Super Admin role.'],
                ]);
            }
        }

        return $roles->values();
    }

    public function ensureAssignablePermissions(User $actor, array $permissionIds, ?User $target = null): Collection
    {
        $permissions = AdminPermission::whereIn('id', $permissionIds)->get()->keyBy('id');

        if (count($permissionIds) !== $permissions->count()) {
            throw ValidationException::withMessages([
                'permissions' => ['One or more selected permissions could not be found.'],
            ]);
        }

        if ($target && $actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => ['You cannot change your own direct permissions.'],
            ]);
        }

        if (! $actor->hasAdminPermission(AdminPermissionRegistry::PERMISSIONS_MANAGE)) {
            throw ValidationException::withMessages([
                'permissions' => ['You are not allowed to assign direct permissions.'],
            ]);
        }

        return $permissions->values();
    }

    public function protectLastSuperAdmin(User $target, ?Collection $replacementRoles = null, ?bool $replacementActive = null): void
    {
        if (! $target->isAdmin() || ! $target->hasAdminRole(AdminRoleRegistry::SUPER_ADMIN)) {
            return;
        }

        $wouldRemainActive = $replacementActive ?? $target->is_active;
        $wouldRemainSuperAdmin = $replacementRoles
            ? $replacementRoles->contains('slug', AdminRoleRegistry::SUPER_ADMIN)
            : true;

        if ($wouldRemainActive && $wouldRemainSuperAdmin) {
            return;
        }

        $activeSuperAdmins = User::query()
            ->where('role', $target->role->value)
            ->where('is_active', true)
            ->whereHas('adminRoles', fn ($query) => $query->where('slug', AdminRoleRegistry::SUPER_ADMIN))
            ->whereKeyNot($target->id)
            ->count();

        if ($activeSuperAdmins === 0) {
            throw ValidationException::withMessages([
                'user' => ['You cannot remove, demote, or deactivate the last active Super Admin.'],
            ]);
        }
    }
}
