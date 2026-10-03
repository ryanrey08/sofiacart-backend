<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlatformReportRequest;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformReportsController extends Controller
{
    public function index(PlatformReportRequest $request): JsonResponse
    {
        $type = $request->string('type')->toString() ?: 'merchant_sales';
        $perPage = (int) $request->integer('per_page', 15);

        $report = $this->reportQuery($type, $request)->paginate($perPage);

        return response()->json([
            'data' => $report->items(),
            'meta' => [
                'type' => $type,
                'filters' => [
                    'merchant_id' => $request->validated('merchant_id'),
                    'date_from' => $request->validated('date_from'),
                    'date_to' => $request->validated('date_to'),
                ],
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
        $query = $this->reportQuery($type, $request);
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

    protected function reportQuery(string $type, PlatformReportRequest $request)
    {
        $merchantId = $request->filled('merchant_id') ? $request->integer('merchant_id') : null;
        $dateFrom = $request->filled('date_from') ? $request->date('date_from')->startOfDay() : null;
        $dateTo = $request->filled('date_to') ? $request->date('date_to')->endOfDay() : null;

        return match ($type) {
            'payment_status' => $this->paymentStatusQuery($merchantId, $dateFrom, $dateTo),
            'order_status' => $this->orderStatusQuery($merchantId, $dateFrom, $dateTo),
            default => $this->merchantSalesQuery($merchantId, $dateFrom, $dateTo),
        };
    }

    /**
     * Merchant sales use order timestamps; the date range limits which orders are summed,
     * so merchants without orders in the range still appear with zero sales.
     */
    protected function merchantSalesQuery(?int $merchantId = null, ?Carbon $dateFrom = null, ?Carbon $dateTo = null)
    {
        return Merchant::query()
            ->leftJoin('orders', function ($join) use ($dateFrom, $dateTo): void {
                $join->on('orders.merchant_id', '=', 'merchants.id');

                if ($dateFrom) {
                    $join->where('orders.ordered_at', '>=', $dateFrom);
                }

                if ($dateTo) {
                    $join->where('orders.ordered_at', '<=', $dateTo);
                }
            })
            ->when($merchantId, fn ($query) => $query->where('merchants.id', $merchantId))
            ->select('merchants.id', 'merchants.store_name', 'merchants.status')
            ->selectRaw('COALESCE(SUM(orders.total_amount), 0) as total_sales')
            ->selectRaw('COUNT(orders.id) as orders_count')
            ->groupBy('merchants.id', 'merchants.store_name', 'merchants.status')
            ->orderByDesc('total_sales');
    }

    protected function paymentStatusQuery(?int $merchantId = null, ?Carbon $dateFrom = null, ?Carbon $dateTo = null)
    {
        return Payment::query()
            ->when($merchantId, fn ($query) => $query->where('merchant_id', $merchantId))
            ->when($dateFrom, fn ($query) => $query->where('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->where('created_at', '<=', $dateTo))
            ->select('status')
            ->selectRaw('COUNT(*) as payments_count')
            ->selectRaw('SUM(amount) as total_amount')
            ->groupBy('status')
            ->orderBy('status');
    }

    protected function orderStatusQuery(?int $merchantId = null, ?Carbon $dateFrom = null, ?Carbon $dateTo = null)
    {
        return Order::query()
            ->when($merchantId, fn ($query) => $query->where('merchant_id', $merchantId))
            ->when($dateFrom, fn ($query) => $query->where('ordered_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->where('ordered_at', '<=', $dateTo))
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
