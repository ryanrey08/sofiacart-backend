<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Http\Resources\Admin\AdminAuditLogResource;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AdminAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function __construct(
        protected AdminAuthorizationService $authorizationService,
    ) {}

    public function index(Request $request)
    {
        $query = User::query()
            ->where('role', UserRole::Admin)
            ->with(['adminRoles.permissions', 'adminPermissions']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return AdminUserResource::collection($query->latest()->paginate((int) $request->integer('per_page', 15)));
    }

    public function store(StoreAdminUserRequest $request)
    {
        $validated = $request->validated();
        $actor = $request->user();
        $roleIds = $validated['role_ids'] ?? [];
        $permissionIds = $validated['permission_ids'] ?? [];
        $roles = $this->authorizationService->ensureAssignableRoles($actor, $roleIds);
        $permissions = $this->authorizationService->ensureAssignablePermissions($actor, $permissionIds);

        $user = DB::transaction(function () use ($validated, $roleIds, $permissionIds): User {
            $user = User::create([
                'role' => UserRole::Admin,
                'is_active' => $validated['is_active'] ?? true,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::password(32)),
            ]);

            $user->adminRoles()->sync($roleIds);
            $user->adminPermissions()->sync($permissionIds);

            return $user;
        });

        $token = Password::broker('users')->createToken($user);

        return response()->json([
            'message' => 'Admin user created successfully.',
            'data' => AdminUserResource::make($user->load(['adminRoles.permissions', 'adminPermissions'])),
            'password_setup' => [
                'email' => $user->email,
                'token' => $token,
                'expires_in_minutes' => config('auth.passwords.users.expire'),
            ],
        ], 201);
    }

    public function show(User $user): AdminUserResource
    {
        $this->ensureAdminUser($user);

        return AdminUserResource::make($user->load(['adminRoles.permissions', 'adminPermissions']));
    }

    public function update(UpdateAdminUserRequest $request, User $user): AdminUserResource
    {
        $this->ensureAdminUser($user);
        $validated = $request->validated();
        $actor = $request->user();

        $roles = array_key_exists('role_ids', $validated)
            ? $this->authorizationService->ensureAssignableRoles($actor, $validated['role_ids'], $user)
            : null;
        $permissions = array_key_exists('permission_ids', $validated)
            ? $this->authorizationService->ensureAssignablePermissions($actor, $validated['permission_ids'], $user)
            : null;

        $this->authorizationService->protectLastSuperAdmin($user, $roles, $validated['is_active'] ?? null);

        DB::transaction(function () use ($validated, $user, $roles, $permissions): void {
            $user->update(collect($validated)
                ->except(['role_ids', 'permission_ids', 'role'])
                ->all());

            if ($roles !== null) {
                $user->adminRoles()->sync($roles->pluck('id')->all());
            }

            if ($permissions !== null) {
                $user->adminPermissions()->sync($permissions->pluck('id')->all());
            }

            $user->load('adminRoles.permissions', 'adminPermissions');

            if (! $user->isActiveAdmin() || $user->allAdminPermissions()->isEmpty()) {
                $user->tokens()->delete();
            }
        });

        return AdminUserResource::make($user->fresh()->load(['adminRoles.permissions', 'adminPermissions']));
    }

    public function destroy(Request $request, User $user)
    {
        $this->ensureAdminUser($user);

        if ($request->user()->is($user)) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own admin account.'],
            ]);
        }

        $this->authorizationService->protectLastSuperAdmin($user, collect(), false);

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json(status: 204);
    }

    public function activity(Request $request, User $user)
    {
        $this->ensureAdminUser($user);

        return AdminAuditLogResource::collection(
            AdminAuditLog::query()
                ->with('actor')
                ->where(function ($query) use ($user): void {
                    $query->where('actor_id', $user->id)
                        ->orWhere(function ($subject) use ($user): void {
                            $subject->where('subject_type', $user->getMorphClass())
                                ->where('subject_id', $user->id);
                        });
                })
                ->latest('created_at')
                ->paginate((int) $request->integer('per_page', 15))
        );
    }

    protected function ensureAdminUser(User $user): void
    {
        if (! $user->isAdmin()) {
            abort(404);
        }
    }
}
