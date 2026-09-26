<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $dateFrom = $request->filled('date_from')
            ? Carbon::parse((string) $request->input('date_from'))->startOfDay()
            : now()->subDays(29)->startOfDay();
        $dateTo = $request->filled('date_to')
            ? Carbon::parse((string) $request->input('date_to'))->endOfDay()
            : now()->endOfDay();

        $orders = Order::query()->whereBetween('ordered_at', [$dateFrom, $dateTo]);
        $payments = Payment::query()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $merchants = Merchant::query()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $customers = Customer::query()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $products = Product::query()->whereBetween('created_at', [$dateFrom, $dateTo]);

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
                    'new_merchants' => (clone $merchants)->count(),
                    'new_customers' => (clone $customers)->count(),
                    'new_products' => (clone $products)->count(),
                ],
                'breakdowns' => [
                    'orders_by_status' => Order::query()
                        ->whereBetween('ordered_at', [$dateFrom, $dateTo])
                        ->select('status', DB::raw('COUNT(*) as total'))
                        ->groupBy('status')
                        ->orderBy('status')
                        ->get(),
                    'merchant_statuses' => Merchant::query()
                        ->select('status', DB::raw('COUNT(*) as total'))
                        ->groupBy('status')
                        ->orderBy('status')
                        ->get(),
                    'top_merchants' => Merchant::query()
                        ->leftJoin('orders', 'orders.merchant_id', '=', 'merchants.id')
                        ->whereBetween('orders.ordered_at', [$dateFrom, $dateTo])
                        ->select('merchants.id', 'merchants.store_name', DB::raw('SUM(orders.total_amount) as total_sales'))
                        ->groupBy('merchants.id', 'merchants.store_name')
                        ->orderByDesc('total_sales')
                        ->limit(5)
                        ->get(),
                ],
            ],
        ]);
    }
}
