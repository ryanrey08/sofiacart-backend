<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMerchantStatusRequest;
use App\Http\Resources\Admin\AdminAuditLogResource;
use App\Http\Resources\Admin\AdminMerchantResource;
use App\Models\AdminAuditLog;
use App\Models\Merchant;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantManagementController extends Controller
{
    public function __construct(
        protected AdminAuditLogger $auditLogger,
    ) {}

    public function index(Request $request)
    {
        $query = Merchant::query()
            ->with('user')
            ->withCount(['orders', 'products'])
            ->withSum('payments', 'amount');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('store_name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('store_slug', 'like', "%{$search}%")
                    ->orWhere('tin', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('email', 'like', "%{$search}%"));
            });
        }

        return AdminMerchantResource::collection(
            $query->latest()->paginate((int) $request->integer('per_page', 15))
        );
    }

    public function show(Merchant $merchant): AdminMerchantResource
    {
        return AdminMerchantResource::make(
            $merchant->load(['user'])->loadCount(['orders', 'products'])->loadSum('payments', 'amount')
        );
    }

    public function updateStatus(UpdateMerchantStatusRequest $request, Merchant $merchant): AdminMerchantResource
    {
        $merchant->update(['status' => $request->validated('status')]);

        $this->auditLogger->log(
            'admin.merchants.status',
            $request->user(),
            $merchant,
            $request,
            'Merchant status updated.',
            [
                'status' => $request->validated('status'),
                'reason' => $request->validated('reason'),
            ],
        );

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
                ->paginate((int) $request->integer('per_page', 15))
        );
    }

    public function billing(Merchant $merchant): JsonResponse
    {
        $merchant->load(['payments.refunds', 'transactions', 'orders']);

        $paymentsTotal = (float) $merchant->payments->sum('amount');
        $refundsTotal = (float) $merchant->payments->flatMap->refunds->sum('amount');

        return response()->json([
            'data' => [
                'merchant_id' => $merchant->id,
                'store_name' => $merchant->store_name,
                'payments_total' => number_format($paymentsTotal, 2, '.', ''),
                'refunds_total' => number_format($refundsTotal, 2, '.', ''),
                'net_total' => number_format($paymentsTotal - $refundsTotal, 2, '.', ''),
                'payments_count' => $merchant->payments->count(),
                'refunds_count' => $merchant->refunds->count(),
                'transactions_count' => $merchant->transactions->count(),
                'recent_payments' => $merchant->payments->sortByDesc('created_at')->take(10)->values(),
            ],
        ]);
    }
}
