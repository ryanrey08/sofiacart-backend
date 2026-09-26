<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminPermissionRequest;
use App\Http\Requests\Admin\UpdateAdminPermissionRequest;
use App\Http\Resources\Admin\AdminPermissionResource;
use App\Models\AdminPermission;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminPermissionController extends Controller
{
    public function index(Request $request)
    {
        $query = AdminPermission::query();

        if ($group = $request->string('group')->toString()) {
            $query->where('group', $group);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%");
            });
        }

        return AdminPermissionResource::collection($query->orderBy('group')->orderBy('name')->paginate((int) $request->integer('per_page', 50)));
    }

    public function store(StoreAdminPermissionRequest $request): AdminPermissionResource
    {
        return AdminPermissionResource::make(AdminPermission::create([
            ...$request->validated(),
            'is_system' => false,
        ]));
    }

    public function show(AdminPermission $permission): AdminPermissionResource
    {
        return AdminPermissionResource::make($permission);
    }

    public function update(UpdateAdminPermissionRequest $request, AdminPermission $permission): AdminPermissionResource
    {
        if ($permission->is_system) {
            throw ValidationException::withMessages([
                'permission' => ['System permissions cannot be modified.'],
            ]);
        }

        $permission->update($request->validated());

        return AdminPermissionResource::make($permission->fresh());
    }

    public function destroy(AdminPermission $permission)
    {
        if ($permission->is_system) {
            throw ValidationException::withMessages([
                'permission' => ['System permissions cannot be deleted.'],
            ]);
        }

        $permission->delete();

        return response()->json(status: 204);
    }
}
