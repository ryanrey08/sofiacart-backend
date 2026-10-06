<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MerchantChangeRequestStatus;
use App\Enums\MerchantStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListMerchantsRequest;
use App\Http\Requests\Admin\UpdateMerchantStatusRequest;
use App\Http\Resources\Admin\AdminAuditLogResource;
use App\Http\Resources\Admin\AdminMerchantResource;
use App\Models\AdminAuditLog;
use App\Models\Merchant;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MerchantManagementController extends Controller
{
    /**
     * Uploaded registration files an admin may review, keyed by the public document name.
     */
    public const DOCUMENTS = [
        'business_permit' => 'business_permit_path',
        'government_id' => 'government_id_path',
        'store_logo' => 'store_logo_path',
        'store_banner' => 'store_banner_path',
    ];

    public function __construct(
        protected AdminAuditLogger $auditLogger,
    ) {}

    public function index(ListMerchantsRequest $request)
    {
        $query = Merchant::query()
            ->with(['user', 'pendingChangeRequest'])
            ->withCount(['orders', 'products'])
            ->withSum([
                'payments' => fn ($payments) => $payments->whereIn('status', $this->collectedPaymentStatuses()),
            ], 'amount');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($request->filled('has_pending_changes')) {
            $request->boolean('has_pending_changes')
                ? $query->whereHas('changeRequests', fn ($changes) => $changes->where('status', MerchantChangeRequestStatus::Pending->value))
                : $query->whereDoesntHave('changeRequests', fn ($changes) => $changes->where('status', MerchantChangeRequestStatus::Pending->value));
        }

        if ($statuses = $request->validated('statuses')) {
            $query->whereIn('status', $statuses);
        }

        if ($storeCategory = $request->string('store_category')->trim()->toString()) {
            $query->where('store_category', $storeCategory);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('store_name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('store_slug', 'like', "%{$search}%")
                    ->orWhere('tin', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('email', 'like', "%{$search}%"));
            });
        }

        match ($request->validated('sort')) {
            'oldest' => $query->oldest()->orderBy('id'),
            'name_asc' => $query->orderBy('store_name')->orderBy('id'),
            'name_desc' => $query->orderByDesc('store_name')->orderByDesc('id'),
            'orders_desc' => $query->orderByDesc('orders_count')->orderByDesc('id'),
            'products_desc' => $query->orderByDesc('products_count')->orderByDesc('id'),
            'collected_desc' => $query->orderByDesc('payments_sum_amount')->orderByDesc('id'),
            default => $query->latest()->orderByDesc('id'),
        };

        return AdminMerchantResource::collection(
            $query->paginate($this->pageSize($request))->withQueryString()
        );
    }

    /**
     * All-time merchant counts per onboarding status for the overview cards.
     */
    public function summary(): JsonResponse
    {
        $counts = Merchant::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = collect(MerchantStatus::cases())
            ->mapWithKeys(fn (MerchantStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)]);

        return response()->json([
            'data' => [
                'total' => $byStatus->sum(),
                'by_status' => $byStatus->all(),
                'store_categories' => Merchant::query()
                    ->whereNotNull('store_category')
                    ->distinct()
                    ->orderBy('store_category')
                    ->pluck('store_category'),
                'pending_profile_changes' => Merchant::query()
                    ->whereHas('changeRequests', fn ($changes) => $changes->where('status', MerchantChangeRequestStatus::Pending->value))
                    ->count(),
            ],
        ]);
    }

    public function show(Merchant $merchant): AdminMerchantResource
    {
        return AdminMerchantResource::make(
            $merchant->load(['user', 'pendingChangeRequest'])
                ->loadCount(['orders', 'products', 'customers', 'payments', 'transactions', 'refunds'])
                ->loadSum([
                    'payments' => fn ($payments) => $payments->whereIn('status', $this->collectedPaymentStatuses()),
                ], 'amount')
        );
    }

    /**
     * Records an onboarding decision. The row is locked so two admins cannot apply the same
     * decision twice, and a decision that does not change the status is rejected.
     */
    public function updateStatus(UpdateMerchantStatusRequest $request, Merchant $merchant): AdminMerchantResource
    {
        $status = MerchantStatus::from($request->validated('status'));
        $reason = $request->validated('reason');

        $merchant = DB::transaction(function () use ($request, $merchant, $status, $reason): Merchant {
            $locked = Merchant::query()->lockForUpdate()->findOrFail($merchant->id);
            $previousStatus = $locked->status;

            if ($previousStatus === $status) {
                throw ValidationException::withMessages([
                    'status' => ["The merchant is already {$status->value}."],
                ]);
            }

            $locked->update(['status' => $status]);

            $this->auditLogger->log(
                'admin.merchants.status',
                $request->user(),
                $locked,
                $request,
                $this->decisionDescription($status),
                [
                    'status' => $status->value,
                    'previous_status' => $previousStatus?->value,
                    'reason' => $reason,
                ],
            );

            return $locked;
        });

        return AdminMerchantResource::make($merchant->fresh()->load('user'));
    }

    public function onboardingHistory(Request $request, Merchant $merchant)
    {
        return AdminAuditLogResource::collection(
            AdminAuditLog::query()
                ->with('actor')
                ->where('subject_type', $merchant->getMorphClass())
                ->where('subject_id', $merchant->id)
                ->where('action', 'like', 'admin.merchants.%')
                ->latest('created_at')
                ->latest('id')
                ->paginate($this->pageSize($request))
        );
    }

    /**
     * Streams a registration document so admins can review it without a public URL.
     */
    public function document(Merchant $merchant, string $document): StreamedResponse
    {
        $column = self::DOCUMENTS[$document] ?? abort(404);
        $path = $merchant->{$column};

        abort_unless($path && Storage::disk('public')->exists($path), 404, 'The document was not found.');

        return Storage::disk('public')->response($path, basename($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function billing(Merchant $merchant): JsonResponse
    {
        $paymentsTotal = (float) $merchant->payments()
            ->whereIn('status', $this->collectedPaymentStatuses())
            ->sum('amount');
        $refundsTotal = (float) $merchant->refunds()
            ->where('status', RefundStatus::Processed)
            ->sum('amount');

        return response()->json([
            'data' => [
                'merchant_id' => $merchant->id,
                'store_name' => $merchant->store_name,
                'payments_total' => number_format($paymentsTotal, 2, '.', ''),
                'refunds_total' => number_format($refundsTotal, 2, '.', ''),
                'net_total' => number_format($paymentsTotal - $refundsTotal, 2, '.', ''),
                'payments_count' => $merchant->payments()
                    ->whereIn('status', $this->collectedPaymentStatuses())
                    ->count(),
                'refunds_count' => $merchant->refunds()
                    ->where('status', RefundStatus::Processed)
                    ->count(),
                'transactions_count' => $merchant->transactions()->count(),
                'recent_payments' => $merchant->payments()
                    ->with('refunds')
                    ->latest('created_at')
                    ->limit(10)
                    ->get(),
            ],
        ]);
    }

    protected function decisionDescription(MerchantStatus $status): string
    {
        return match ($status) {
            MerchantStatus::Verified => 'Merchant approved.',
            MerchantStatus::Rejected => 'Merchant rejected.',
            MerchantStatus::InformationRequested => 'Merchant asked for more information.',
            MerchantStatus::Suspended => 'Merchant suspended.',
            MerchantStatus::Pending => 'Merchant returned to pending review.',
        };
    }

    protected function collectedPaymentStatuses(): array
    {
        return [
            PaymentStatus::Completed->value,
            PaymentStatus::PartiallyRefunded->value,
            PaymentStatus::Refunded->value,
        ];
    }
}
