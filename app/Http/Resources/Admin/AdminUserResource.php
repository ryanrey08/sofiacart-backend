<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class AdminUserResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toISOString(),
            'admin_roles' => AdminRoleResource::collection($this->whenLoaded('adminRoles')),
            'admin_permissions' => AdminPermissionResource::collection($this->whenLoaded('adminPermissions')),
            'effective_permissions' => $this->when(
                $this->relationLoaded('adminRoles') || $this->relationLoaded('adminPermissions'),
                fn () => $this->allAdminPermissions()->pluck('name')->values()->all(),
            ),
        ]);
    }
}
