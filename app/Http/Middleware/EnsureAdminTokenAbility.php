<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminTokenAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token || ! $token->can('admin')) {
            return new JsonResponse([
                'message' => 'An admin-scoped API token is required.',
            ], 403);
        }

        return $next($request);
    }
}
