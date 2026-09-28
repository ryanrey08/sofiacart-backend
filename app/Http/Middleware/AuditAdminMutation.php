<?php

namespace App\Http\Middleware;

use App\Services\AdminAuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAdminMutation
{
    protected const EXCLUDED_ROUTE_NAMES = [
        'admin.merchants.status',
    ];

    public function __construct(
        protected AdminAuditLogger $auditLogger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || $response->getStatusCode() >= 400 || $this->shouldSkipAudit($request)) {
            return $response;
        }

        $subject = collect($request->route()?->parameters() ?? [])
            ->first(fn ($parameter) => $parameter instanceof Model);

        $this->auditLogger->log(
            action: $request->route()?->getName() ?? $request->method().' '.$request->path(),
            actor: $request->user(),
            subject: $subject instanceof Model ? $subject : null,
            request: $request,
            description: 'Admin mutation executed.',
            metadata: [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => $request->route()?->getName(),
                'parameters' => collect($request->route()?->parameters() ?? [])
                    ->map(fn ($value) => $value instanceof Model ? $value->getKey() : $value)
                    ->all(),
            ],
        );

        return $response;
    }

    protected function shouldSkipAudit(Request $request): bool
    {
        return in_array($request->route()?->getName(), self::EXCLUDED_ROUTE_NAMES, true);
    }
}
