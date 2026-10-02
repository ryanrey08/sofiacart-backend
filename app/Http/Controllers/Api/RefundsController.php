<?php

namespace App\Http\Controllers\Api;

use App\Enums\RefundStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRefundsRequest;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Requests\UpdateRefundRequest;
use App\Http\Requests\UpdateRefundStatusRequest;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Transaction;
use App\Services\RefundService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RefundsController extends Controller
{
    use InteractsWithMerchantScope;

    public function __construct(
        protected RefundService $refundService
    ) {}

    public function index(ListRefundsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Refund::class);

        $query = $this->refundsQuery($request)->with(['order.customer', 'payment']);

        foreach (['status', 'payment_id', 'order_id', 'return_request_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('customer_id')) {
            $query->whereHas('order', fn (Builder $order) => $order->where('customer_id', $request->integer('customer_id')));
        }

        if ($request->filled('payment_method')) {
            $query->whereHas('payment', fn (Builder $payment) => $payment->where('method', $request->validated('payment_method')));
        }

        if ($reason = $request->string('reason')->trim()->toString()) {
            $query->where('reason', 'like', "%{$reason}%");
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('order', fn (Builder $order) => $order->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        match ($request->validated('sort')) {
            'oldest' => $query->oldest()->orderBy('id'),
            'amount_desc' => $query->orderByDesc('amount')->orderByDesc('id'),
            'amount_asc' => $query->orderBy('amount')->orderBy('id'),
            default => $query->latest()->orderByDesc('id'),
        };

        return RefundResource::collection($query->paginate($this->pageSize($request))->withQueryString());
    }

    /**
     * Totals for the Refunds overview cards (current month by default).
     */
    public function summary(ListRefundsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Refund::class);

        $from = $request->filled('date_from') ? $request->date('date_from')->startOfDay() : now()->startOfMonth();
        $to = $request->filled('date_to') ? $request->date('date_to')->endOfDay() : now()->endOfDay();
        $previousFrom = $from->copy()->subSeconds($to->diffInSeconds($from) + 1);
        $previousTo = $from->copy()->subSecond();

        $counts = $this->refundsQuery($request)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $byStatus = collect(RefundStatus::cases())->mapWithKeys(fn (RefundStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)]);

        $refunded = fn ($start, $end): float => (float) $this->refundsQuery($request)
            ->where('status', RefundStatus::Processed)
            ->whereBetween('refunded_at', [$start, $end])
            ->sum('amount');
        $current = $refunded($from, $to);
        $previous = $refunded($previousFrom, $previousTo);

        return response()->json(['data' => [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'total_refunds' => $byStatus->sum(),
            'by_status' => $byStatus,
            'refunded_amount' => number_format($current, 2, '.', ''),
            'previous_refunded_amount' => number_format($previous, 2, '.', ''),
            'refunded_change_percent' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
            'currency' => config('payments.currency'),
        ]]);
    }

    /**
     * Request a refund (201), or return the refund already created for the same
     * Idempotency-Key (200).
     */
    public function store(StoreRefundRequest $request): RefundResource
    {
        Gate::authorize('create', Refund::class);

        $data = $request->validated();
        $merchantId = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);

        [$refund] = $this->refundService->request($data, $merchantId, $request->user()?->id, $request->header('Idempotency-Key'));

        return $this->detail($refund);
    }

    /**
     * What can still be refunded on an order: per-item quantities and per-payment balances
     * (the New Refund Request form).
     */
    public function refundable(Request $request, int $order): JsonResponse
    {
        Gate::authorize('viewAny', Refund::class);

        $model = $this->scopeMerchant(Order::query(), $request)->findOrFail($order);

        return response()->json(['data' => [
            'order_id' => $model->id,
            'order_number' => $model->order_number,
            'order_status' => $model->status?->value,
            'payment_status' => $model->payment_status?->value,
            'currency' => config('payments.currency'),
            ...$this->refundService->refundableSummary($model),
        ]]);
    }

    public function show(Request $request, int $refund): RefundResource
    {
        $model = $this->scopeMerchant(Refund::query(), $request)->findOrFail($refund);
        Gate::authorize('view', $model);

        return $this->detail($model);
    }

    /**
     * Correct refund details (amount, payment/order link, reason). Status changes use updateStatus().
     */
    public function update(UpdateRefundRequest $request, int $refund): RefundResource
    {
        $candidate = $this->scopeMerchant(Refund::query(), $request)->findOrFail($refund);
        Gate::authorize('update', $candidate);

        $updatedRefund = DB::transaction(function () use ($request, $candidate): Refund {
            [$originalPayment, $originalOrder] = $this->refundService->lockPaymentAndOrder(
                $candidate->merchant_id,
                $candidate->payment_id,
                $candidate->order_id,
            );
            $model = Refund::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_if(ReturnRequest::where('refund_id', $model->id)->exists(), 409, 'A processed return uses this refund.');

            $data = $request->validated();
            $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);

            $changesMoney = collect(['amount', 'payment_id', 'order_id', 'merchant_id'])
                ->contains(fn (string $field) => array_key_exists($field, $data) && (string) $data[$field] !== (string) $model->{$field});
            if ($changesMoney && ($model->return_request_id || $model->items()->exists())) {
                throw ValidationException::withMessages([
                    'amount' => ['The amount, payment and order of an item or return refund are derived from its items and cannot be edited.'],
                ]);
            }
            [$payment, $order] = $this->refundService->lockPaymentAndOrder(
                $data['merchant_id'],
                $data['payment_id'] ?? $model->payment_id,
                $data['order_id'] ?? $model->order_id,
            );
            $this->refundService->ensureRefundable($payment, (float) ($data['amount'] ?? $model->amount), $model->status, $model);

            $model->update([...$data, 'order_id' => $order?->id]);

            if ($originalPayment->id !== $payment->id || $originalOrder?->id !== $order?->id) {
                $this->refundService->syncBalances($model, $originalPayment, $originalOrder);
            }

            $this->refundService->syncBalances($model, $payment->refresh(), $order?->refresh());

            return $model;
        });

        return $this->detail($updatedRefund);
    }

    /**
     * Approve, reject or process (pay out) a refund.
     */
    public function updateStatus(UpdateRefundStatusRequest $request, int $refund): RefundResource
    {
        $model = $this->scopeMerchant(Refund::query(), $request)->findOrFail($refund);
        Gate::authorize('update', $model);

        $updated = $this->refundService->transition(
            $model,
            RefundStatus::from($request->validated('status')),
            $request->safe()->only(['notes', 'payout_reference', 'failure_reason', 'refunded_at', 'cancel_order']),
            $request->user()?->id,
        );

        return $this->detail($updated);
    }

    public function destroy(Request $request, int $refund): JsonResponse
    {
        $candidate = $this->scopeMerchant(Refund::query(), $request)->findOrFail($refund);
        Gate::authorize('delete', $candidate);

        DB::transaction(function () use ($candidate): void {
            $order = $candidate->order_id ? Order::where('merchant_id', $candidate->merchant_id)->lockForUpdate()->find($candidate->order_id) : null;
            $payment = Payment::where('merchant_id', $candidate->merchant_id)->lockForUpdate()->find($candidate->payment_id);
            $model = Refund::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_if(ReturnRequest::where('refund_id', $model->id)->exists(), 409, 'A processed return uses this refund.');
            abort_if(in_array($model->status, [RefundStatus::Processing, RefundStatus::Processed], true),
                409, 'Refunds that are being paid out or were completed cannot be deleted.');

            Transaction::where('refund_id', $model->id)->update([
                'status' => TransactionStatus::Failed,
                'description' => 'Refund '.$model->reference.' deleted',
            ]);
            $model->delete();

            $this->refundService->syncPaymentAndOrder($payment, $order);
        });

        return response()->json(status: 204);
    }

    protected function detail(Refund $refund): RefundResource
    {
        return RefundResource::make($refund->refresh()->load([
            'payment', 'order.customer', 'order.items', 'items.orderItem', 'returnRequest', 'processedReturn',
            'requester', 'reviewer', 'transactions', 'histories.user',
        ]));
    }

    protected function refundsQuery(Request $request): Builder
    {
        $query = $this->scopeMerchant(Refund::query(), $request);

        if ($this->isAdmin($request) && $request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        return $query;
    }
}
