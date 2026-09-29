<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use App\Services\AdminAuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    protected const ADMIN_TOKEN_PREFIX = 'admin:';

    public function __construct(
        protected AdminAuditLogger $auditLogger,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::with(['adminRoles.permissions', 'adminPermissions'])
            ->where('email', $validated['email'])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isActiveAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['The provided account does not have active administrator access.'],
            ]);
        }

        $token = $user->createToken(
            $this->adminTokenName($validated['device_name'] ?? 'admin-api-token'),
            ['admin'],
            now()->addMinutes((int) config('admin.auth.token_ttl_minutes', 120)),
        )->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->save();

        $this->auditLogger->log('admin.auth.login', $user, $user, $request, 'Admin login successful.');

        return response()->json([
            'message' => 'Admin login successful.',
            'token' => $token,
            'user' => AdminUserResource::make($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->user()?->currentAccessToken()?->delete();

        if ($user) {
            $this->auditLogger->log('admin.auth.logout', $user, $user, $request, 'Admin logout successful.');
        }

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $deleted = $this->adminTokens($user)->each->delete()->count();

        if ($user) {
            $this->auditLogger->log('admin.auth.logout_all', $user, $user, $request, 'All admin sessions revoked.', [
                'revoked_tokens' => $deleted,
            ]);
        }

        return response()->json([
            'message' => 'All admin sessions revoked successfully.',
        ]);
    }

    public function me(Request $request): AdminUserResource
    {
        return AdminUserResource::make(
            $request->user()->loadMissing(['adminRoles.permissions', 'adminPermissions'])
        );
    }

    public function sessions(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()?->currentAccessToken()?->getKey();

        return response()->json([
            'data' => $this->adminTokens($request->user())
                ->sortByDesc('created_at')
                ->map(fn ($token): array => [
                    'id' => $token->id,
                    'name' => Str::after($token->name, self::ADMIN_TOKEN_PREFIX),
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toISOString(),
                    'created_at' => $token->created_at?->toISOString(),
                    'expires_at' => $token->expires_at?->toISOString(),
                    'is_current' => $token->id === $currentTokenId,
                ])
                ->values(),
        ]);
    }

    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $token = $this->adminTokens($request->user())
            ->firstWhere('id', $tokenId);

        abort_if(! $token, 404);

        $token->delete();

        $this->auditLogger->log('admin.auth.revoke_session', $request->user(), $request->user(), $request, 'Admin session revoked.', [
            'revoked_token_id' => $tokenId,
        ]);

        return response()->json([
            'message' => 'Admin session revoked successfully.',
        ]);
    }

    protected function adminTokens(?User $user)
    {
        if (! $user) {
            return collect();
        }

        return $user->tokens()
            ->get()
            ->filter(fn (PersonalAccessToken $token) => $token->can('admin'))
            ->values();
    }

    protected function adminTokenName(string $deviceName): string
    {
        return self::ADMIN_TOKEN_PREFIX.$deviceName;
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user?->isActiveAdmin()) {
            try {
                Password::broker('users')->sendResetLink(['email' => $validated['email']]);
            } catch (\Throwable $exception) {
                Log::warning('Admin password reset link delivery failed.', [
                    'email' => $validated['email'],
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'If the account exists, a password reset link has been queued.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $resetUser = User::where('email', $validated['email'])->first();

        if (! $resetUser?->isActiveAdmin()) {
            throw ValidationException::withMessages([
                'email' => [__(Password::INVALID_TOKEN)],
            ]);
        }

        $status = Password::broker('users')->reset(
            $validated,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        if (($user = User::where('email', $validated['email'])->first())?->isActiveAdmin()) {
            $this->auditLogger->log('admin.auth.password_reset', $user, $user, $request, 'Admin password reset completed.');
        }

        return response()->json([
            'message' => __($status),
        ]);
    }
}
