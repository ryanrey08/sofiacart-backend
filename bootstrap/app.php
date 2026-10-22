<?php

use App\Http\Middleware\AuditAdminMutation;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureAdminTokenAbility;
use App\Http\Middleware\EnsureMerchantEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the nginx/HTTPS reverse proxy; trust its forwarded scheme and host headers.
        $middleware->trustProxies(at: '*');
        // API-only app: never redirect unauthenticated requests to a (non-existent) login route;
        // the exception renders as a 401 JSON response instead (no 500 or stack trace to probes).
        $middleware->redirectGuestsTo(fn () => null);
        // Default rate limit on all API routes (sensitive routes keep their own stricter throttles).
        $middleware->throttleApi();
        // Baseline security headers on every response.
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'admin.permission' => EnsureAdminPermission::class,
            'admin.audit' => AuditAdminMutation::class,
            'admin.token' => EnsureAdminTokenAbility::class,
            'merchant.verified' => EnsureMerchantEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Always answer API auth failures with 401 JSON, even without an Accept header, so the
        // framework never tries to redirect to a (non-existent) login route and leak a 500/trace.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
