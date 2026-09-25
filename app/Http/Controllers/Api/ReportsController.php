<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    use InteractsWithMerchantScope;

    public function sales(Request $request): JsonResponse
    {
        $query = Order::query();
        $this->scopeMerchant($query, $request);
        $this->applyDateRange($query, $request, 'ordered_at');

        $sales = $query->selectRaw('DATE(ordered_at) as report_date, SUM(total_amount) as total_sales, COUNT(*) as orders_count')
            ->groupBy('report_date')
            ->orderBy('report_date')
            ->get();

        return response()->json(['data' => $sales]);
    }

    public function customers(Request $request): JsonResponse
    {
        $newCustomers = Customer::query();
        $this->scopeMerchant($newCustomers, $request);
        $this->applyDateRange($newCustomers, $request);

        $topCustomers = Customer::query()
            ->select('customers.id', 'customers.name', DB::raw('SUM(orders.total_amount) as total_spent'))
            ->join('orders', 'orders.customer_id', '=', 'customers.id')
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total_spent')
            ->limit((int) $request->integer('limit', 5));
        $this->scopeMerchant($topCustomers, $request, 'customers.merchant_id');

        return response()->json([
            'data' => [
                'new_customers_count' => $newCustomers->count(),
                'top_customers' => $topCustomers->get(),
            ],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $topProducts = OrderItem::query()
            ->select('products.id', 'products.name', 'products.sku', DB::raw('SUM(order_items.quantity) as total_quantity_sold'))
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_quantity_sold')
            ->limit((int) $request->integer('limit', 5));
        $this->scopeMerchant($topProducts, $request, 'orders.merchant_id');

        $lowStock = Product::query();
        $this->scopeMerchant($lowStock, $request);
        $threshold = (int) $request->integer('low_stock_threshold', 10);

        return response()->json([
            'data' => [
                'top_selling' => $topProducts->get(),
                'low_stock' => $lowStock->where('stock_quantity', '<=', $threshold)
                    ->orderBy('stock_quantity')
                    ->limit((int) $request->integer('limit', 5))
                    ->get(['id', 'name', 'sku', 'stock_quantity']),
            ],
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $query = InventoryLog::query();
        $this->scopeMerchant($query, $request);
        $this->applyDateRange($query, $request, 'created_at');

        $summary = [
            'total_logs' => (clone $query)->count(),
            'net_quantity_change' => (int) ((clone $query)->sum('quantity_change') ?? 0),
            'by_reason' => (clone $query)
                ->select('reason', DB::raw('COUNT(*) as total_logs'), DB::raw('SUM(quantity_change) as net_quantity_change'))
                ->groupBy('reason')
                ->orderByDesc('total_logs')
                ->get(),
        ];

        return response()->json(['data' => $summary]);
    }

    protected function applyDateRange($query, Request $request, string $column = 'created_at'): void
    {
        if ($request->filled('date_from')) {
            $query->whereDate($column, '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate($column, '<=', $request->date('date_to'));
        }
    }
}
