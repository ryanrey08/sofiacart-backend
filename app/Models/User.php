<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'role',
        'is_active',
        'last_login_at',
        'phone',
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function merchant(): HasOne
    {
        return $this->hasOne(Merchant::class);
    }

    public function adminRoles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRole::class, 'admin_role_user')
            ->withTimestamps();
    }

    public function adminPermissions(): BelongsToMany
    {
        return $this->belongsToMany(AdminPermission::class, 'admin_permission_user')
            ->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isActiveAdmin(): bool
    {
        return $this->isAdmin() && $this->is_active;
    }

    public function allAdminPermissions(): Collection
    {
        $this->loadMissing('adminRoles.permissions', 'adminPermissions');

        return $this->adminRoles
            ->flatMap(fn (AdminRole $role) => $role->permissions)
            ->merge($this->adminPermissions)
            ->unique('id')
            ->values();
    }

    public function hasAdminPermission(string $permission): bool
    {
        if (! $this->isActiveAdmin()) {
            return false;
        }

        return $this->allAdminPermissions()->contains('name', $permission);
    }

    public function hasAdminRole(string $roleSlug): bool
    {
        $this->loadMissing('adminRoles');

        return $this->adminRoles->contains('slug', $roleSlug);
    }
}
