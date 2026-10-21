<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks merchant features until the merchant has verified their email address. Admin users are
 * unaffected. The `code` lets the frontend send the merchant to the verification screen.
 */
class EnsureMerchantEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->role === UserRole::Merchant && ! $user->hasVerifiedEmail()) {
            return new JsonResponse([
                'message' => 'Verify your email address to use merchant features.',
                'code' => 'email_unverified',
            ], 403);
        }

        return $next($request);
    }
}
