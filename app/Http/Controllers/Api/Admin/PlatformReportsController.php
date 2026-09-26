<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformReportsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString() ?: 'merchant_sales';
        $perPage = (int) $request->integer('per_page', 15);

        $report = match ($type) {
            'payment_status' => $this->paymentStatusReport($perPage),
            'order_status' => $this->orderStatusReport($perPage),
            default => $this->merchantSalesReport($perPage),
        };

        return response()->json([
            'data' => $report->items(),
            'meta' => [
                'type' => $type,
                'current_page' => $report->currentPage(),
                'last_page' => $report->lastPage(),
                'per_page' => $report->perPage(),
                'total' => $report->total(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $type = $request->string('type')->toString() ?: 'merchant_sales';
        $rows = match ($type) {
            'payment_status' => $this->paymentStatusReport(500)->items(),
            'order_status' => $this->orderStatusReport(500)->items(),
            default => $this->merchantSalesReport(500)->items(),
        };

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            if ($rows === []) {
                fputcsv($handle, ['no_data']);
                fclose($handle);

                return;
            }

            fputcsv($handle, array_keys((array) $rows[0]));
            foreach ($rows as $row) {
                fputcsv($handle, (array) $row);
            }
            fclose($handle);
        }, "{$type}.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function merchantSalesReport(int $perPage): LengthAwarePaginator
    {
        return Merchant::query()
            ->leftJoin('orders', 'orders.merchant_id', '=', 'merchants.id')
            ->select('merchants.id', 'merchants.store_name', 'merchants.status')
            ->selectRaw('COALESCE(SUM(orders.total_amount), 0) as total_sales')
            ->selectRaw('COUNT(orders.id) as orders_count')
            ->groupBy('merchants.id', 'merchants.store_name', 'merchants.status')
            ->orderByDesc('total_sales')
            ->paginate($perPage);
    }

    protected function paymentStatusReport(int $perPage): LengthAwarePaginator
    {
        return Payment::query()
            ->select('status')
            ->selectRaw('COUNT(*) as payments_count')
            ->selectRaw('SUM(amount) as total_amount')
            ->groupBy('status')
            ->orderBy('status')
            ->paginate($perPage);
    }

    protected function orderStatusReport(int $perPage): LengthAwarePaginator
    {
        return Order::query()
            ->select('status', 'payment_status')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('SUM(total_amount) as total_amount')
            ->groupBy('status', 'payment_status')
            ->orderBy('status')
            ->paginate($perPage);
    }
}
