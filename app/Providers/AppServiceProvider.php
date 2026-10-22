<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Default limit for every API route (authenticated endpoints are keyed by user, else IP).
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()));

        RateLimiter::for('admin-auth', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        // Limits current-password guessing through the change-password endpoint.
        RateLimiter::for('account-password', function (Request $request): Limit {
            return Limit::perMinute(5)->by('user:'.($request->user()?->getKey() ?? $request->ip()));
        });

        // Public legal document metadata and files.
        RateLimiter::for('legal-documents', function (Request $request): Limit {
            return Limit::perMinute(60)->by('ip:'.$request->ip());
        });

        // Verification tokens are 64 random characters; this only slows automated guessing.
        RateLimiter::for('merchant-email-verify', function (Request $request): Limit {
            return Limit::perMinute(10)->by('ip:'.$request->ip());
        });

        // Applied per address (or signed-in user) and per IP, whether or not the address is
        // registered, so the limit itself doesn't reveal which addresses have accounts.
        RateLimiter::for('merchant-email-resend', function (Request $request): array {
            $identity = $request->user()
                ? 'user:'.$request->user()->getKey()
                : 'email:'.Str::lower(trim((string) $request->input('email')));

            $tooManyRequests = fn (Request $request, array $headers): JsonResponse => new JsonResponse([
                'message' => 'Please wait before requesting another verification email.',
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);

            return [
                Limit::perMinute(1)->by('minute|'.$identity)->response($tooManyRequests),
                Limit::perHour(5)->by('hour|'.$identity)->response($tooManyRequests),
                Limit::perMinute(10)->by('ip|'.$request->ip())->response($tooManyRequests),
            ];
        });
    }
}
