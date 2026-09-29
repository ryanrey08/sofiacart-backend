<?php

namespace App\Http\Controllers\Api\Admin;

use App\Admin\AdminPermissionRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminRoleRequest;
use App\Http\Requests\Admin\UpdateAdminRoleRequest;
use App\Http\Resources\Admin\AdminRoleResource;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminRoleController extends Controller
{
    public function index(Request $request)
    {
        $query = AdminRole::query()->with('permissions');

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return AdminRoleResource::collection($query->latest()->paginate($this->pageSize($request)));
    }

    public function store(StoreAdminRoleRequest $request): AdminRoleResource
    {
        $validated = $request->validated();
        $permissionIds = $validated['permission_ids'] ?? [];
        $this->assertDelegatablePermissions($request->user(), $permissionIds);

        $role = DB::transaction(function () use ($validated, $permissionIds): AdminRole {
            $role = AdminRole::create([
                'slug' => $validated['slug'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_system' => false,
            ]);

            $role->permissions()->sync($permissionIds);

            return $role;
        });

        return AdminRoleResource::make($role->load('permissions'));
    }

    public function show(AdminRole $role): AdminRoleResource
    {
        return AdminRoleResource::make($role->load('permissions'));
    }

    public function update(UpdateAdminRoleRequest $request, AdminRole $role): AdminRoleResource
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => ['System roles cannot be modified.'],
            ]);
        }

        $validated = $request->validated();
        $permissionIds = $validated['permission_ids'] ?? null;

        if ($permissionIds !== null) {
            $this->assertDelegatablePermissions($request->user(), $permissionIds);
        }

        DB::transaction(function () use ($validated, $permissionIds, $role): void {
            $role->update(collect($validated)->except('permission_ids')->all());

            if ($permissionIds !== null) {
                $role->permissions()->sync($permissionIds);
            }
        });

        return AdminRoleResource::make($role->fresh()->load('permissions'));
    }

    public function destroy(AdminRole $role)
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => ['System roles cannot be deleted.'],
            ]);
        }

        $role->delete();

        return response()->json(status: 204);
    }

    protected function assertDelegatablePermissions($actor, array $permissionIds): void
    {
        if ($permissionIds === []) {
            return;
        }

        $selectedNames = AdminPermission::whereIn('id', $permissionIds)->pluck('name');
        $assignableNames = $actor->allAdminPermissions()->pluck('name');

        $forbidden = $selectedNames->diff($assignableNames);

        if ($forbidden->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission_ids' => ['You cannot delegate permissions you do not currently hold.'],
            ]);
        }

        if ($selectedNames->contains(AdminPermissionRegistry::USERS_ASSIGN_SUPER_ADMIN)
            && ! $actor->hasAdminPermission(AdminPermissionRegistry::USERS_ASSIGN_SUPER_ADMIN)) {
            throw ValidationException::withMessages([
                'permission_ids' => ['You cannot delegate Super Admin assignment rights.'],
            ]);
        }
    }
}
