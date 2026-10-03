<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListTransactionsRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read access to the transaction ledger. Rows are written only by PaymentService and
 * RefundService, so there is no create or delete endpoint and updates are limited to metadata.
 */
class TransactionsController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(ListTransactionsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Transaction::class);

        $query = $this->transactionsQuery($request)->with(['payment', 'refund', 'order.customer'])
            ->when($this->isAdmin($request), fn (Builder $query) => $query->with('merchant:id,store_name,store_slug'));

        foreach (['type', 'status', 'order_id', 'payment_id', 'refund_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('payment_method')) {
            $query->whereHas('payment', fn (Builder $payment) => $payment->where('method', $request->validated('payment_method')));
        }

        if ($request->filled('customer_id')) {
            $query->whereHas('order', fn (Builder $order) => $order->where('customer_id', $request->integer('customer_id')));
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('payment', fn (Builder $payment) => $payment->where('reference', 'like', "%{$search}%")
                        ->orWhere('gateway_reference', 'like', "%{$search}%"))
                    ->orWhereHas('refund', fn (Builder $refund) => $refund->where('reference', 'like', "%{$search}%"))
                    ->orWhereHas('order', fn (Builder $order) => $order->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")));

                if (is_numeric($amount = str_replace(',', '', $search))) {
                    $builder->orWhere('amount', round((float) $amount, 2));
                }
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('transacted_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('transacted_at', '<=', $request->date('date_to'));
        }

        match ($request->validated('sort')) {
            'oldest' => $query->orderBy('transacted_at')->orderBy('id'),
            'amount_desc' => $query->orderByDesc('amount')->orderByDesc('id'),
            'amount_asc' => $query->orderBy('amount')->orderBy('id'),
            default => $query->orderByDesc('transacted_at')->orderByDesc('id'),
        };

        return TransactionResource::collection($query->paginate($this->pageSize($request))->withQueryString());
    }

    /**
     * Totals for the Transactions overview cards (current month by default). "Sales" are
     * orders placed in the period; transactions themselves are payments and refunds.
     */
    public function summary(ListTransactionsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Transaction::class);

        $from = $request->filled('date_from') ? $request->date('date_from')->startOfDay() : now()->startOfMonth();
        $to = $request->filled('date_to') ? $request->date('date_to')->endOfDay() : now()->endOfDay();
        $previousFrom = $from->copy()->subSeconds($to->diffInSeconds($from) + 1);
        $previousTo = $from->copy()->subSecond();

        $inPeriod = fn (Carbon $start, Carbon $end): Builder => $this->transactionsQuery($request)->whereBetween('transacted_at', [$start, $end]);
        $countBy = fn (string $column): Collection => $inPeriod($from, $to)
            ->select($column, DB::raw('COUNT(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column);
        $completedSum = fn (array $types): float => (float) $inPeriod($from, $to)
            ->where('status', TransactionStatus::Completed)
            ->whereIn('type', $types)
            ->sum('amount');

        $byType = $countBy('type');
        $byStatus = $countBy('status');
        $total = (int) $byType->sum();
        $previousTotal = $inPeriod($previousFrom, $previousTo)->count();
        $collected = $completedSum([TransactionType::Payment, TransactionType::Credit]);
        $refunded = $completedSum([TransactionType::Refund, TransactionType::Debit]);

        $salesOrders = $this->scopeMerchant(Order::query(), $request)
            ->when($this->isAdmin($request) && $request->filled('merchant_id'), fn (Builder $query) => $query->where('merchant_id', $request->integer('merchant_id')))
            ->whereBetween('ordered_at', [$from, $to])
            ->count();

        return response()->json(['data' => [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'total_transactions' => $total,
            'previous_total_transactions' => $previousTotal,
            'total_change_percent' => $previousTotal > 0 ? round(($total - $previousTotal) / $previousTotal * 100, 1) : null,
            'sales_orders' => $salesOrders,
            'by_type' => collect(TransactionType::cases())->mapWithKeys(fn (TransactionType $type) => [$type->value => (int) ($byType[$type->value] ?? 0)]),
            'by_status' => collect(TransactionStatus::cases())->mapWithKeys(fn (TransactionStatus $status) => [$status->value => (int) ($byStatus[$status->value] ?? 0)]),
            'collected_amount' => $this->money($collected),
            'refunded_amount' => $this->money($refunded),
            'net_amount' => $this->money($collected - $refunded),
            'currency' => config('payments.currency'),
        ]]);
    }

    /**
     * Transaction details with the order/payment ledger history and a timeline built from
     * recorded timestamps.
     */
    public function show(Request $request, int $transaction): TransactionResource
    {
        $model = $this->scopeMerchant(Transaction::query(), $request)
            ->with(['payment', 'refund', 'order.customer'])
            ->findOrFail($transaction);
        Gate::authorize('view', $model);

        $history = Transaction::query()
            ->where('merchant_id', $model->merchant_id)
            ->where(fn (Builder $query) => $model->order_id
                ? $query->where('order_id', $model->order_id)
                : $query->where('payment_id', $model->payment_id ?? 0)->orWhereKey($model->id))
            ->with(['payment', 'refund'])
            ->orderBy('transacted_at')
            ->orderBy('id')
            ->get();

        return TransactionResource::make($model)->additional(['meta' => [
            'history' => TransactionResource::collection($history),
            'timeline' => $this->timeline($model->order, $history),
        ]]);
    }

    /**
     * Annotate a transaction's metadata (e.g. reconciliation notes). Everything else is immutable.
     */
    public function update(UpdateTransactionRequest $request, int $transaction): TransactionResource
    {
        $model = $this->scopeMerchant(Transaction::query(), $request)->findOrFail($transaction);
        Gate::authorize('update', $model);

        $model->update(['metadata' => array_replace($model->metadata ?? [], $request->validated('metadata'))]);

        return TransactionResource::make($model->refresh()->load(['payment', 'refund', 'order.customer']));
    }

    /**
     * @param  Collection<int, Transaction>  $history
     * @return list<array{event: string, title: string, description: string, occurred_at: ?string, transaction_id: ?int}>
     */
    protected function timeline(?Order $order, Collection $history): array
    {
        $events = collect();

        if ($order) {
            $events->push([
                'event' => 'order_created',
                'title' => 'Order Created',
                'description' => "Order {$order->order_number} was created.",
                'occurred_at' => $order->ordered_at,
                'transaction_id' => null,
            ]);
        }

        foreach ($history as $entry) {
            $completed = $entry->status === TransactionStatus::Completed;
            [$event, $title] = match ($entry->type) {
                TransactionType::Payment => $completed ? ['payment_received', 'Payment Received'] : ['payment_failed', 'Payment Failed'],
                TransactionType::Refund => $completed ? ['refund_issued', 'Refund Issued'] : ['refund_reversed', 'Refund Reversed'],
                TransactionType::Credit => ['credit_recorded', 'Credit Recorded'],
                TransactionType::Debit => ['debit_recorded', 'Debit Recorded'],
            };

            $events->push([
                'event' => $event,
                'title' => $title,
                'description' => $entry->description ?? "{$title}: {$this->money((float) $entry->amount)}",
                'occurred_at' => $entry->transacted_at ?? $entry->created_at,
                'transaction_id' => $entry->id,
            ]);
        }

        return $events
            ->sortBy(fn (array $event) => $event['occurred_at']?->getTimestamp() ?? PHP_INT_MAX)
            ->map(fn (array $event) => [...$event, 'occurred_at' => $event['occurred_at']?->toISOString()])
            ->values()
            ->all();
    }

    protected function transactionsQuery(Request $request): Builder
    {
        $query = $this->scopeMerchant(Transaction::query(), $request);

        if ($this->isAdmin($request) && $request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        return $query;
    }

    protected function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
