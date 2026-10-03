<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MerchantStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardRequest;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Transaction;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService,
    ) {}

    public function __invoke(DashboardRequest $request): JsonResponse
    {
        $dateFrom = $request->filled('date_from')
            ? Carbon::parse((string) $request->input('date_from'))->startOfDay()
            : now()->subDays(29)->startOfDay();
        $dateTo = $request->filled('date_to')
            ? Carbon::parse((string) $request->input('date_to'))->endOfDay()
            : now()->endOfDay();

        $orders = Order::query()->whereBetween('ordered_at', [$dateFrom, $dateTo]);
        $payments = Payment::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereIn('status', $this->collectedPaymentStatuses());
        $merchants = Merchant::query()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $customers = Customer::query()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $products = Product::query()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $processedRefunds = Refund::query()
            ->where('status', RefundStatus::Processed)
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        return response()->json([
            'data' => [
                'date_range' => [
                    'from' => $dateFrom->toISOString(),
                    'to' => $dateTo->toISOString(),
                    'semantics' => 'Inclusive UTC date range using order timestamps for revenue metrics and record created_at timestamps for entity counts.',
                ],
                'metrics' => [
                    'orders_count' => (clone $orders)->count(),
                    'gross_sales' => (string) ((clone $orders)->sum('total_amount') ?? '0.00'),
                    'payments_collected' => (string) ((clone $payments)->sum('amount') ?? '0.00'),
                    'payments_count' => (clone $payments)->count(),
                    'refunds_processed' => number_format((float) (clone $processedRefunds)->sum('amount'), 2, '.', ''),
                    'refunds_count' => (clone $processedRefunds)->count(),
                    'transactions_count' => Transaction::query()->whereBetween('transacted_at', [$dateFrom, $dateTo])->count(),
                    'new_merchants' => (clone $merchants)->count(),
                    'new_customers' => (clone $customers)->count(),
                    'new_products' => (clone $products)->count(),
                ],
                'totals' => $this->platformTotals(),
                'breakdowns' => [
                    'orders_by_status' => Order::query()
                        ->whereBetween('ordered_at', [$dateFrom, $dateTo])
                        ->select('status', DB::raw('COUNT(*) as total'))
                        ->groupBy('status')
                        ->orderBy('status')
                        ->get(),
                    'merchant_statuses' => Merchant::query()
                        ->whereBetween('created_at', [$dateFrom, $dateTo])
                        ->select('status', DB::raw('COUNT(*) as total'))
                        ->groupBy('status')
                        ->orderBy('status')
                        ->get(),
                    'top_merchants' => Merchant::query()
                        ->leftJoin('orders', function ($join) use ($dateFrom, $dateTo): void {
                            $join->on('orders.merchant_id', '=', 'merchants.id')
                                ->whereBetween('orders.ordered_at', [$dateFrom, $dateTo]);
                        })
                        ->select('merchants.id', 'merchants.store_name', DB::raw('SUM(orders.total_amount) as total_sales'))
                        ->groupBy('merchants.id', 'merchants.store_name')
                        ->orderByDesc('total_sales')
                        ->limit(5)
                        ->get(),
                ],
                'series' => $this->dailySeries($dateFrom, $dateTo),
            ],
        ]);
    }

    /**
     * All-time platform counts that do not depend on the selected date range.
     *
     * @return array<string, mixed>
     */
    protected function platformTotals(): array
    {
        $merchantCounts = Merchant::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $merchantStatuses = collect(MerchantStatus::cases())
            ->mapWithKeys(fn (MerchantStatus $status) => [$status->value => (int) ($merchantCounts[$status->value] ?? 0)]);
        $inventory = $this->inventoryService->summarize($this->inventoryService->inventoryItemsQuery(null));

        return [
            'merchants' => $merchantStatuses->sum(),
            'merchants_by_status' => $merchantStatuses->all(),
            'pending_onboarding' => $merchantStatuses[MerchantStatus::Pending->value] + $merchantStatuses[MerchantStatus::InformationRequested->value],
            'customers' => Customer::query()->count(),
            'products' => Product::query()->count(),
            'orders' => Order::query()->count(),
            'inventory' => [
                'total_items' => $inventory['total_items'],
                'low_stock' => $inventory['low_stock'],
                'out_of_stock' => $inventory['out_of_stock'],
                'total_available' => $inventory['total_available'],
                'total_reserved' => $inventory['total_reserved'],
            ],
        ];
    }

    /**
     * One row per day in the range with new merchants, orders, gross sales and collected payments,
     * using the same definitions as the headline metrics.
     *
     * @return list<array{date: string, new_merchants: int, orders_count: int, gross_sales: string, payments_collected: string}>
     */
    protected function dailySeries(Carbon $dateFrom, Carbon $dateTo): array
    {
        $merchants = $this->groupByDay(Merchant::query(), 'created_at', $dateFrom, $dateTo, 'COUNT(*)');
        $orderCounts = $this->groupByDay(Order::query(), 'ordered_at', $dateFrom, $dateTo, 'COUNT(*)');
        $orderSales = $this->groupByDay(Order::query(), 'ordered_at', $dateFrom, $dateTo, 'SUM(total_amount)');
        $collected = $this->groupByDay(
            Payment::query()->whereIn('status', $this->collectedPaymentStatuses()),
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
                'new_merchants' => (int) ($merchants[$key] ?? 0),
                'orders_count' => (int) ($orderCounts[$key] ?? 0),
                'gross_sales' => number_format((float) ($orderSales[$key] ?? 0), 2, '.', ''),
                'payments_collected' => number_format((float) ($collected[$key] ?? 0), 2, '.', ''),
            ];
        }

        return $series;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Collection<string, mixed>
     */
    protected function groupByDay($query, string $column, Carbon $dateFrom, Carbon $dateTo, string $aggregate): Collection
    {
        return $query->whereBetween($column, [$dateFrom, $dateTo])
            ->selectRaw("DATE({$column}) as day, {$aggregate} as value")
            ->groupBy('day')
            ->pluck('value', 'day');
    }

    /**
     * @return list<string>
     */
    protected function collectedPaymentStatuses(): array
    {
        return [
            PaymentStatus::Completed->value,
            PaymentStatus::PartiallyRefunded->value,
            PaymentStatus::Refunded->value,
        ];
    }
}
