<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\MerchantDashboardRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Transaction;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Store overview for the signed-in merchant. Range-scoped metrics and series use the selected
 * window (default: last 30 days); entity totals and inventory are all-time. Every query is
 * restricted to the merchant through InteractsWithMerchantScope, which also lets an admin token
 * read a merchant's figures via ?merchant_id, mirroring the other v1 endpoints.
 */
class MerchantDashboardController extends Controller
{
    use InteractsWithMerchantScope;

    public function __construct(
        protected InventoryService $inventoryService,
    ) {}

    public function __invoke(MerchantDashboardRequest $request): JsonResponse
    {
        $dateFrom = $request->filled('date_from')
            ? Carbon::parse((string) $request->input('date_from'))->startOfDay()
            : now()->subDays(29)->startOfDay();
        $dateTo = $request->filled('date_to')
            ? Carbon::parse((string) $request->input('date_to'))->endOfDay()
            : now()->endOfDay();

        $merchantId = $this->resolveMerchantId($request);

        $orders = fn (): Builder => $this->scoped(Order::query(), $request)->whereBetween('ordered_at', [$dateFrom, $dateTo]);
        $collectedPayments = fn (): Builder => $this->scoped(Payment::query(), $request)
            ->whereIn('status', PaymentStatus::captured())
            ->whereBetween('created_at', [$dateFrom, $dateTo]);
        $processedRefunds = fn (): Builder => $this->scoped(Refund::query(), $request)
            ->where('status', RefundStatus::Processed)
            ->whereBetween('refunded_at', [$dateFrom, $dateTo]);

        $ordersCount = $orders()->count();
        $grossSales = (float) $orders()->sum('total_amount');
        $collected = (float) $collectedPayments()->sum('amount');
        $refunded = (float) $processedRefunds()->sum('amount');

        return response()->json([
            'data' => [
                'date_range' => [
                    'from' => $dateFrom->toISOString(),
                    'to' => $dateTo->toISOString(),
                    'semantics' => 'Inclusive date range: order timestamps for sales/orders, payment created_at for collected amounts, refund refunded_at for refunds. Entity totals and inventory are all-time.',
                ],
                'currency' => config('payments.currency'),
                'metrics' => [
                    'orders_count' => $ordersCount,
                    'paid_orders_count' => $orders()->where('payment_status', OrderPaymentStatus::Paid)->count(),
                    'gross_sales' => $this->money($grossSales),
                    'average_order_value' => $this->money($ordersCount > 0 ? $grossSales / $ordersCount : 0),
                    'payments_collected' => $this->money($collected),
                    'payments_count' => $collectedPayments()->count(),
                    'refunds_processed' => $this->money($refunded),
                    'refunds_count' => $processedRefunds()->count(),
                    'net_revenue' => $this->money($collected - $refunded),
                    'new_customers' => $this->scoped(Customer::query(), $request)->whereBetween('created_at', [$dateFrom, $dateTo])->count(),
                    'new_products' => $this->scoped(Product::query(), $request)->whereBetween('created_at', [$dateFrom, $dateTo])->count(),
                ],
                'totals' => $this->storeTotals($request, $merchantId),
                'breakdowns' => [
                    'orders_by_status' => $this->countByColumn($orders(), 'status'),
                    'payments_by_status' => $this->countByColumn(
                        $this->scoped(Payment::query(), $request)->whereBetween('created_at', [$dateFrom, $dateTo]),
                        'status',
                    ),
                    'top_products' => $this->topProducts($request, $dateFrom, $dateTo),
                    'low_stock' => $this->lowStock($merchantId),
                ],
                'series' => $this->dailySeries($request, $dateFrom, $dateTo),
                'recent' => [
                    'orders' => $this->recentOrders($request),
                    'transactions' => $this->recentTransactions($request),
                ],
            ],
        ]);
    }

    /**
     * Apply tenant scoping. Non-admin users are restricted to their own merchant; an admin may
     * target one merchant with ?merchant_id, matching the other v1 controllers.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    protected function scoped(Builder $query, Request $request, string $column = 'merchant_id'): Builder
    {
        $this->scopeMerchant($query, $request, $column);

        if ($this->isAdmin($request) && $request->filled('merchant_id')) {
            $query->where($column, $request->integer('merchant_id'));
        }

        return $query;
    }

    protected function resolveMerchantId(Request $request): ?int
    {
        if (! $this->isAdmin($request)) {
            return $this->requiredMerchantId($request);
        }

        return $request->filled('merchant_id') ? $request->integer('merchant_id') : null;
    }

    /**
     * All-time store figures that do not depend on the selected range.
     *
     * @return array<string, mixed>
     */
    protected function storeTotals(Request $request, ?int $merchantId): array
    {
        $productCounts = $this->countByColumn($this->scoped(Product::query(), $request), 'status');
        $productsByStatus = collect(ProductStatus::cases())
            ->mapWithKeys(fn (ProductStatus $status) => [
                $status->value => (int) $productCounts->firstWhere('status', $status->value)?->total,
            ]);

        $inventory = $this->inventoryService->summarize($this->inventoryService->inventoryItemsQuery($merchantId));

        return [
            'orders' => $this->scoped(Order::query(), $request)->count(),
            'pending_orders' => $this->scoped(Order::query(), $request)->where('status', OrderStatus::Pending)->count(),
            'customers' => $this->scoped(Customer::query(), $request)->count(),
            'products' => $productsByStatus->sum(),
            'products_by_status' => $productsByStatus->all(),
            'pending_payments' => $this->scoped(Payment::query(), $request)->where('status', PaymentStatus::Pending)->count(),
            'inventory' => [
                'total_items' => $inventory['total_items'],
                'low_stock' => $inventory['low_stock'],
                'out_of_stock' => $inventory['out_of_stock'],
                'total_available' => $inventory['total_available'],
            ],
        ];
    }

    /**
     * Best-selling products by units sold in the range, from actual order items.
     *
     * @return Collection<int, object>
     */
    protected function topProducts(Request $request, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereBetween('orders.ordered_at', [$dateFrom, $dateTo])
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(order_items.quantity) as quantity_sold'),
                DB::raw('SUM(order_items.total_price) as revenue'),
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('quantity_sold')
            ->limit(5);

        $this->scopeMerchantOnTable($query, $request, 'orders.merchant_id');

        return $query->get()->map(fn ($row) => (object) [
            'id' => (int) $row->id,
            'name' => $row->name,
            'sku' => $row->sku,
            'quantity_sold' => (int) $row->quantity_sold,
            'revenue' => $this->money((float) $row->revenue),
        ]);
    }

    /**
     * Low- and out-of-stock items for this merchant, scarcest first.
     *
     * @return Collection<int, object>
     */
    protected function lowStock(?int $merchantId): Collection
    {
        return $this->inventoryService->inventoryItemsQuery($merchantId)
            ->whereIn('stock_status', ['low_stock', 'out_of_stock'])
            ->orderBy('available')
            ->limit(5)
            ->get([
                'product_id',
                'product_variant_id',
                'name',
                'sku',
                'available',
                'low_stock_threshold',
                'stock_status',
            ])
            ->map(fn ($row) => (object) [
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'sku' => $row->sku,
                'available' => (int) $row->available,
                'low_stock_threshold' => (int) $row->low_stock_threshold,
                'stock_status' => $row->stock_status,
            ]);
    }

    /**
     * One row per day in the range: orders, gross sales and collected payments.
     *
     * @return list<array{date: string, orders_count: int, gross_sales: string, payments_collected: string}>
     */
    protected function dailySeries(Request $request, Carbon $dateFrom, Carbon $dateTo): array
    {
        $orderCounts = $this->groupByDay($this->scoped(Order::query(), $request), 'ordered_at', $dateFrom, $dateTo, 'COUNT(*)');
        $orderSales = $this->groupByDay($this->scoped(Order::query(), $request), 'ordered_at', $dateFrom, $dateTo, 'SUM(total_amount)');
        $collected = $this->groupByDay(
            $this->scoped(Payment::query(), $request)->whereIn('status', PaymentStatus::captured()),
            'created_at',
            $dateFrom,
            $dateTo,
            'SUM(amount)',
        );

        $series = [];
        for ($day = $dateFrom->copy()->startOfDay(); $day->lte($dateTo); $day->addDay()) {
            $key = $day->toDateString();
            $series[] = [
                'date' => $key,
                'orders_count' => (int) ($orderCounts[$key] ?? 0),
                'gross_sales' => $this->money((float) ($orderSales[$key] ?? 0)),
                'payments_collected' => $this->money((float) ($collected[$key] ?? 0)),
            ];
        }

        return $series;
    }

    /**
     * @return Collection<int, object>
     */
    protected function recentOrders(Request $request): Collection
    {
        return $this->scoped(Order::query(), $request)
            ->with(['customer:id,name'])
            ->withCount('items')
            ->latest('ordered_at')
            ->limit(5)
            ->get(['id', 'merchant_id', 'customer_id', 'order_number', 'status', 'payment_status', 'total_amount', 'ordered_at'])
            ->map(fn (Order $order) => (object) [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer?->name,
                'items_count' => (int) ($order->items_count ?? 0),
                'total_amount' => $this->money((float) $order->total_amount),
                'status' => $order->status->value,
                'payment_status' => $order->payment_status->value,
                'ordered_at' => $order->ordered_at?->toISOString(),
            ]);
    }

    /**
     * @return Collection<int, object>
     */
    protected function recentTransactions(Request $request): Collection
    {
        $query = DB::table('transactions')
            ->leftJoin('payments', 'payments.id', '=', 'transactions.payment_id')
            ->orderByDesc('transactions.transacted_at')
            ->limit(5)
            ->select(
                'transactions.id',
                'transactions.reference',
                'transactions.type',
                'transactions.status',
                'transactions.amount',
                'transactions.transacted_at',
                'payments.method as method',
            );

        $this->scopeMerchantOnTable($query, $request, 'transactions.merchant_id');

        return $query->get()->map(fn ($row) => (object) [
            'id' => (int) $row->id,
            'reference' => $row->reference,
            'type' => $row->type,
            'method' => $row->method,
            'amount' => $this->money((float) $row->amount),
            'status' => $row->status,
            'transacted_at' => $row->transacted_at ? Carbon::parse($row->transacted_at)->toISOString() : null,
        ]);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Collection<int, object>
     */
    protected function countByColumn(Builder $query, string $column): Collection
    {
        return $query->select($column . ' as status', DB::raw('COUNT(*) as total'))
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn ($row) => (object) ['status' => $row->status, 'total' => (int) $row->total]);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Collection<string, mixed>
     */
    protected function groupByDay(Builder $query, string $column, Carbon $dateFrom, Carbon $dateTo, string $aggregate): Collection
    {
        return $query->whereBetween($column, [$dateFrom, $dateTo])
            ->selectRaw("DATE({$column}) as day, {$aggregate} as value")
            ->groupBy('day')
            ->pluck('value', 'day');
    }

    /**
     * Tenant scoping for raw query-builder statements (joins), where the Eloquent trait helper
     * cannot be used directly.
     */
    protected function scopeMerchantOnTable(\Illuminate\Database\Query\Builder $query, Request $request, string $column): void
    {
        if (! $this->isAdmin($request)) {
            $query->where($column, $this->requiredMerchantId($request));

            return;
        }

        if ($request->filled('merchant_id')) {
            $query->where($column, $request->integer('merchant_id'));
        }
    }

    protected function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
