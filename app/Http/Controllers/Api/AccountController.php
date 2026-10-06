<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\UserResource;
use App\Notifications\AccountPasswordChanged;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in merchant's own account (personal details and password). The account is always
 * `$request->user()` from the Sanctum token; no user id is ever read from the request.
 * Reading the account is the existing GET /api/auth/me.
 */
class AccountController extends Controller
{
    public function __construct(
        protected AdminAuditLogger $auditLogger,
    ) {}

    public function update(UpdateAccountRequest $request): UserResource
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'email', 'phone']));

        if ($user->isDirty('email')) {
            // The new address has not been verified.
            $user->email_verified_at = null;
        }

        $fields = array_values(array_intersect(['name', 'email', 'phone'], array_keys($user->getDirty())));

        if ($fields !== []) {
            $user->save();

            $this->auditLogger->log(
                'merchant.account.updated',
                $user,
                $user,
                $request,
                'Merchant updated their account details.',
                ['fields' => $fields],
            );
        }

        return UserResource::make($user->fresh()->load('merchant'));
    }

    /**
     * Sets a new password after checking the current one. Other sessions are signed out; the
     * session making the change stays signed in.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // The `hashed` cast on User::password hashes the value before it is stored.
        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        $otherTokens = $user->tokens();
        $current = $user->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $otherTokens->whereKeyNot($current->getKey());
        }
        $revoked = $otherTokens->delete();

        $this->auditLogger->log(
            'merchant.account.password_changed',
            $user,
            $user,
            $request,
            'Merchant changed their account password.',
            ['other_sessions_revoked' => $revoked],
        );

        rescue(fn () => $user->notify(new AccountPasswordChanged));

        return response()->json([
            'message' => 'Your password was changed. Other signed-in sessions were signed out.',
        ]);
    }
}
