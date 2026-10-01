<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentsController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = Payment::query();
        $this->scopeMerchant($query, $request);

        foreach (['status', 'gateway', 'order_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($search = $request->string('search')->toString()) {
            $query->where('reference', 'like', "%{$search}%");
        }

        return PaymentResource::collection($query->latest()->paginate($this->pageSize($request)));
    }

    public function store(StorePaymentRequest $request): PaymentResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureOrderBelongsToMerchant($data['merchant_id'], $data['order_id'] ?? null);

        $payment = DB::transaction(function () use ($data): Payment {
            $payment = Payment::create($data);
            $this->syncOrderPaymentStatus($data['merchant_id'], $payment->order_id);

            return $payment;
        });

        return PaymentResource::make($payment);
    }

    public function show(Request $request, int $payment): PaymentResource
    {
        return PaymentResource::make($this->scopeMerchant(Payment::query(), $request)->findOrFail($payment));
    }

    public function update(UpdatePaymentRequest $request, int $payment): PaymentResource
    {
        $model = $this->scopeMerchant(Payment::query(), $request)->findOrFail($payment);
        $data = $request->validated();
        $merchantId = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        $data['merchant_id'] = $merchantId;
        $this->ensureOrderBelongsToMerchant($merchantId, $data['order_id'] ?? $model->order_id);

        $paymentModel = DB::transaction(function () use ($model, $data, $merchantId): Payment {
            $originalOrderId = $model->order_id;
            $model->update($data);

            $this->syncOrderPaymentStatus($merchantId, $model->order_id);
            if ($originalOrderId && $originalOrderId !== $model->order_id) {
                $this->syncOrderPaymentStatus($merchantId, $originalOrderId);
            }

            return $model->fresh();
        });

        return PaymentResource::make($paymentModel);
    }

    public function destroy(Request $request, int $payment)
    {
        $model = $this->scopeMerchant(Payment::query(), $request)->findOrFail($payment);
        $merchantId = $model->merchant_id;
        $orderId = $model->order_id;

        DB::transaction(function () use ($model, $merchantId, $orderId): void {
            $model->delete();
            $this->syncOrderPaymentStatus($merchantId, $orderId);
        });

        return response()->json(status: 204);
    }

    protected function ensureOrderBelongsToMerchant(int $merchantId, ?int $orderId): void
    {
        if ($orderId) {
            Order::where('merchant_id', $merchantId)->findOrFail($orderId);
        }
    }

    protected function syncOrderPaymentStatus(int $merchantId, ?int $orderId): void
    {
        if (! $orderId) {
            return;
        }

        $order = Order::where('merchant_id', $merchantId)->lockForUpdate()->find($orderId);
        if (! $order) {
            return;
        }

        $refundedTotal = (float) Refund::where('order_id', $order->id)
            ->where('status', RefundStatus::Processed)
            ->sum('amount');

        $completedTotal = (float) Payment::where('order_id', $order->id)
            ->where('status', PaymentStatus::Completed)
            ->sum('amount');

        $orderTotal = (float) $order->total_amount;

        if ($refundedTotal > 0 && $refundedTotal >= $orderTotal && $orderTotal > 0) {
            $order->update(['payment_status' => OrderPaymentStatus::Refunded]);
        } elseif ($refundedTotal > 0) {
            $order->update(['payment_status' => OrderPaymentStatus::PartiallyRefunded]);
        } elseif ($completedTotal >= $orderTotal && ($completedTotal > 0 || $orderTotal == 0)) {
            $hasCompletedPayment = Payment::where('order_id', $order->id)->where('status', PaymentStatus::Completed)->exists();
            $order->update(['payment_status' => $hasCompletedPayment ? OrderPaymentStatus::Paid : OrderPaymentStatus::Unpaid]);
        } else {
            $order->update(['payment_status' => OrderPaymentStatus::Unpaid]);
        }
    }
}
