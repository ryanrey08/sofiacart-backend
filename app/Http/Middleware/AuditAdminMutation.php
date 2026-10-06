<?php

namespace App\Http\Middleware;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AdminAuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAdminMutation
{
    protected const EXCLUDED_ROUTE_NAMES = [
        'admin.merchants.status',
        // Logged by MerchantProfileChangeService with the change request and fields.
        'admin.merchant-change-requests.status',
        // Marking one's own notifications read is not an administrative mutation.
        'admin.notifications.read',
        'admin.notifications.read-all',
    ];

    /**
     * Controllers shared with the merchant API receive plain IDs instead of bound models,
     * so the route parameter name identifies which entity the mutation targeted.
     *
     * @var array<string, class-string<Model>>
     */
    protected const SUBJECT_PARAMETERS = [
        'merchant' => Merchant::class,
        'customer' => Customer::class,
        'order' => Order::class,
        'product' => Product::class,
        'payment' => Payment::class,
        'transaction' => Transaction::class,
        'refund' => Refund::class,
        'returnRequest' => ReturnRequest::class,
        'user' => User::class,
        'role' => AdminRole::class,
        'permission' => AdminPermission::class,
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

        $parameters = $request->route()?->parameters() ?? [];
        $subject = $this->resolveSubject($parameters);

        $this->auditLogger->log(
            action: $request->route()?->getName() ?? $request->method().' '.$request->path(),
            actor: $request->user(),
            subject: $subject,
            request: $request,
            description: 'Admin mutation executed.',
            metadata: [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => $request->route()?->getName(),
                'parameters' => collect($parameters)
                    ->map(fn ($value) => $value instanceof Model ? $value->getKey() : $value)
                    ->all(),
                'fields' => $this->changedFields($request),
            ],
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function resolveSubject(array $parameters): ?Model
    {
        foreach ($parameters as $name => $value) {
            if ($value instanceof Model) {
                return $value;
            }

            if (isset(self::SUBJECT_PARAMETERS[$name]) && is_numeric($value)) {
                $model = new (self::SUBJECT_PARAMETERS[$name]);

                return $model->newQuery()->find((int) $value) ?? $model->forceFill([$model->getKeyName() => (int) $value]);
            }
        }

        return null;
    }

    /**
     * Records which fields were submitted (and status values, which are not sensitive) without
     * persisting request bodies that could contain credentials or payment details.
     *
     * @return array<string, mixed>
     */
    protected function changedFields(Request $request): array
    {
        $fields = collect($request->except(['password', 'password_confirmation', 'token', '_method']))
            ->keys()
            ->values()
            ->all();

        return array_filter([
            'submitted' => $fields,
            'status' => is_string($request->input('status')) ? $request->input('status') : null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    protected function shouldSkipAudit(Request $request): bool
    {
        return in_array($request->route()?->getName(), self::EXCLUDED_ROUTE_NAMES, true);
    }
}
