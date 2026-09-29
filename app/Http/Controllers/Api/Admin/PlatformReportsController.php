<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlatformReportRequest;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformReportsController extends Controller
{
    public function index(PlatformReportRequest $request): JsonResponse
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

    public function export(PlatformReportRequest $request): StreamedResponse
    {
        $type = $request->string('type')->toString() ?: 'merchant_sales';
        $query = match ($type) {
            'payment_status' => $this->paymentStatusQuery(),
            'order_status' => $this->orderStatusQuery(),
            default => $this->merchantSalesQuery(),
        };
        $columns = $this->exportColumns($type);

        return response()->streamDownload(function () use ($query, $columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            foreach ($query->cursor() as $row) {
                fputcsv($handle, array_map(function (string $column) use ($row) {
                    $value = $row->getAttribute($column);

                    return $value instanceof BackedEnum ? $value->value : $value;
                }, $columns));
            }
            fclose($handle);
        }, "{$type}.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function merchantSalesReport(int $perPage): LengthAwarePaginator
    {
        return $this->merchantSalesQuery()->paginate($perPage);
    }

    protected function paymentStatusReport(int $perPage): LengthAwarePaginator
    {
        return $this->paymentStatusQuery()->paginate($perPage);
    }

    protected function orderStatusReport(int $perPage): LengthAwarePaginator
    {
        return $this->orderStatusQuery()->paginate($perPage);
    }

    protected function merchantSalesQuery()
    {
        return Merchant::query()
            ->leftJoin('orders', 'orders.merchant_id', '=', 'merchants.id')
            ->select('merchants.id', 'merchants.store_name', 'merchants.status')
            ->selectRaw('COALESCE(SUM(orders.total_amount), 0) as total_sales')
            ->selectRaw('COUNT(orders.id) as orders_count')
            ->groupBy('merchants.id', 'merchants.store_name', 'merchants.status')
            ->orderByDesc('total_sales');
    }

    protected function paymentStatusQuery()
    {
        return Payment::query()
            ->select('status')
            ->selectRaw('COUNT(*) as payments_count')
            ->selectRaw('SUM(amount) as total_amount')
            ->groupBy('status')
            ->orderBy('status');
    }

    protected function orderStatusQuery()
    {
        return Order::query()
            ->select('status', 'payment_status')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('SUM(total_amount) as total_amount')
            ->groupBy('status', 'payment_status')
            ->orderBy('status');
    }

    protected function exportColumns(string $type): array
    {
        return match ($type) {
            'payment_status' => ['status', 'payments_count', 'total_amount'],
            'order_status' => ['status', 'payment_status', 'orders_count', 'total_amount'],
            default => ['id', 'store_name', 'status', 'total_sales', 'orders_count'],
        };
    }
}
