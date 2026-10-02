<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListPaymentsRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Requests\UpdatePaymentStatusRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentsController extends Controller
{
    use InteractsWithMerchantScope;

    public function __construct(
        protected PaymentService $paymentService
    ) {}

    public function index(ListPaymentsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Payment::class);

        $query = $this->paymentsQuery($request)->with('order.customer');

        foreach (['status', 'method', 'gateway', 'order_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('customer_id')) {
            $query->whereHas('order', fn (Builder $order) => $order->where('customer_id', $request->integer('customer_id')));
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('reference', 'like', "%{$search}%")
                    ->orWhere('gateway_reference', 'like', "%{$search}%")
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
            'paid_at_desc' => $query->orderByDesc('paid_at')->orderByDesc('id'),
            'paid_at_asc' => $query->orderBy('paid_at')->orderBy('id'),
            default => $query->latest()->orderByDesc('id'),
        };

        return PaymentResource::collection($query->paginate($this->pageSize($request))->withQueryString());
    }

    /**
     * Totals for the Payments overview cards. Defaults to the current month and compares
     * collections with the preceding period of equal length.
     */
    public function summary(ListPaymentsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);

        $from = $request->filled('date_from') ? $request->date('date_from')->startOfDay() : now()->startOfMonth();
        $to = $request->filled('date_to') ? $request->date('date_to')->endOfDay() : now()->endOfDay();
        $previousFrom = $from->copy()->subSeconds($to->diffInSeconds($from) + 1);
        $previousTo = $from->copy()->subSecond();

        $counts = $this->paymentsQuery($request)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $byStatus = collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)]);

        $collected = $this->collectedBetween($request, $from, $to);
        $previousCollected = $this->collectedBetween($request, $previousFrom, $previousTo);
        $refunded = (float) $this->scopeMerchant(Refund::query(), $request)
            ->when($this->isAdmin($request) && $request->filled('merchant_id'), fn (Builder $query) => $query->where('merchant_id', $request->integer('merchant_id')))
            ->where('status', RefundStatus::Processed)
            ->whereBetween('refunded_at', [$from, $to])
            ->sum('amount');

        return response()->json(['data' => [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'total_payments' => $byStatus->sum(),
            'completed_payments' => collect(PaymentStatus::captured())->sum(fn (PaymentStatus $status) => $byStatus[$status->value]),
            'by_status' => $byStatus,
            'collected_amount' => $this->money($collected),
            'previous_collected_amount' => $this->money($previousCollected),
            'collected_change_percent' => $previousCollected > 0 ? round(($collected - $previousCollected) / $previousCollected * 100, 1) : null,
            'refunded_amount' => $this->money($refunded),
            'currency' => config('payments.currency'),
        ]]);
    }

    /**
     * Record a payment: initialise a pending payment or record money already received.
     */
    public function store(StorePaymentRequest $request): PaymentResource
    {
        Gate::authorize('create', Payment::class);

        $data = $request->safe()->except('attachments');
        $merchantId = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);

        $payment = $this->paymentService->record($data, $merchantId, $request->user()?->id, $request->file('attachments', []));

        return $this->detail($payment);
    }

    public function show(Request $request, int $payment): PaymentResource
    {
        $model = $this->scopeMerchant(Payment::query(), $request)->findOrFail($payment);
        Gate::authorize('view', $model);

        return $this->detail($model);
    }

    /**
     * Edit payment details (reference, method, notes...). Status changes use updateStatus().
     */
    public function update(UpdatePaymentRequest $request, int $payment): PaymentResource
    {
        $model = $this->scopeMerchant(Payment::query(), $request)->findOrFail($payment);
        Gate::authorize('update', $model);

        $data = $request->validated();
        $merchantId = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        unset($data['merchant_id']);

        abort_if(ReturnRequest::whereHas('refund', fn ($query) => $query->where('payment_id', $model->id))->exists(),
            409, 'A processed return uses this payment.');

        return $this->detail($this->paymentService->updateDetails($model, $data, $merchantId));
    }

    /**
     * Verify (complete), fail or cancel a pending payment.
     */
    public function updateStatus(UpdatePaymentStatusRequest $request, int $payment): PaymentResource
    {
        $model = $this->scopeMerchant(Payment::query(), $request)->findOrFail($payment);
        Gate::authorize('update', $model);

        $updated = $this->paymentService->transition(
            $model,
            PaymentStatus::from($request->validated('status')),
            $request->safe()->only(['reason', 'paid_at', 'gateway_reference', 'notes']),
            $request->user()?->id,
        );

        return $this->detail($updated);
    }

    /**
     * Only payments that never collected money can be deleted.
     */
    public function destroy(Request $request, int $payment): JsonResponse
    {
        $model = $this->scopeMerchant(Payment::query(), $request)->findOrFail($payment);
        Gate::authorize('delete', $model);

        DB::transaction(function () use ($model): void {
            $order = $model->order_id ? Order::whereKey($model->order_id)->lockForUpdate()->first() : null;
            $model = Payment::whereKey($model->id)->lockForUpdate()->firstOrFail();
            abort_if($model->status->isCaptured() || $model->refunds()->exists() || $model->transactions()->exists(),
                409, 'Collected payments cannot be deleted; refund them instead.');
            $model->delete();

            if ($order) {
                $this->paymentService->syncOrderPaymentStatus($order);
            }
        });

        Storage::disk(config('payments.attachments.disk'))->delete(array_column($model->attachments ?? [], 'path'));

        return response()->json(status: 204);
    }

    /**
     * Download a payment proof attachment.
     */
    public function attachment(Request $request, int $payment, int $index): StreamedResponse
    {
        $model = $this->scopeMerchant(Payment::query(), $request)->findOrFail($payment);
        Gate::authorize('view', $model);

        $file = ($model->attachments ?? [])[$index] ?? null;
        abort_unless($file, 404);

        return Storage::disk(config('payments.attachments.disk'))->download($file['path'], basename($file['name']));
    }

    /**
     * What is owed and collected for an order (Record Payment "Due Amount" / "Payment Status After Recording").
     */
    public function orderBalance(Request $request, int $order): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);

        $model = $this->scopeMerchant(Order::query(), $request)->findOrFail($order);
        $payments = $model->payments()->latest()->orderByDesc('id')->get();

        return response()->json(['data' => [
            'order_id' => $model->id,
            'order_number' => $model->order_number,
            'order_status' => $model->status?->value,
            'payment_status' => $model->payment_status?->value,
            'currency' => config('payments.currency'),
            ...$this->paymentService->orderBalance($model),
            'payments' => PaymentResource::collection($payments),
        ]]);
    }

    protected function detail(Payment $payment): PaymentResource
    {
        $payment->refresh()->load(['order.customer', 'order.items', 'transactions', 'refunds', 'verifier']);

        return PaymentResource::make($payment)->additional([
            'meta' => ['order_balance' => $payment->order ? $this->paymentService->orderBalance($payment->order) : null],
        ]);
    }

    protected function paymentsQuery(Request $request): Builder
    {
        $query = $this->scopeMerchant(Payment::query(), $request);

        if ($this->isAdmin($request) && $request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        return $query;
    }

    protected function collectedBetween(Request $request, Carbon $from, Carbon $to): float
    {
        return (float) $this->paymentsQuery($request)
            ->whereIn('status', PaymentStatus::captured())
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }

    protected function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
