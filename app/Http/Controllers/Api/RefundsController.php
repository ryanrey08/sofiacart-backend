<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Requests\UpdateRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundsController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = Refund::query();
        $this->scopeMerchant($query, $request);

        foreach (['status', 'payment_id', 'order_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($search = $request->string('search')->toString()) {
            $query->where('reference', 'like', "%{$search}%");
        }

        return RefundResource::collection($query->latest()->paginate((int) $request->integer('per_page', 15)));
    }

    public function store(StoreRefundRequest $request): RefundResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        [$payment, $order] = $this->ensureRelationsBelongToMerchant($data['merchant_id'], $data['payment_id'], $data['order_id'] ?? null);
        $this->ensureRefundIsAllowed($payment, $data['amount']);

        $refund = DB::transaction(function () use ($data, $payment, $order): Refund {
            $refund = Refund::create($data);
            $this->syncRefundedBalances($payment, $order);

            return $refund;
        });

        return RefundResource::make($refund);
    }

    public function show(Request $request, int $refund): RefundResource
    {
        return RefundResource::make($this->scopeMerchant(Refund::query(), $request)->findOrFail($refund));
    }

    public function update(UpdateRefundRequest $request, int $refund): RefundResource
    {
        $model = $this->scopeMerchant(Refund::query(), $request)->findOrFail($refund);
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        [$payment, $order] = $this->ensureRelationsBelongToMerchant($data['merchant_id'], $data['payment_id'] ?? $model->payment_id, $data['order_id'] ?? $model->order_id);
        $this->ensureRefundIsAllowed($payment, $data['amount'] ?? (float) $model->amount, $model);

        DB::transaction(function () use ($data, $model, $payment, $order): void {
            $model->update($data);
            $this->syncRefundedBalances($payment, $order);
        });

        return RefundResource::make($model->fresh());
    }

    public function destroy(Request $request, int $refund)
    {
        $this->scopeMerchant(Refund::query(), $request)->findOrFail($refund)->delete();

        return response()->json(status: 204);
    }

    protected function ensureRelationsBelongToMerchant(int $merchantId, int $paymentId, ?int $orderId): array
    {
        $payment = Payment::where('merchant_id', $merchantId)->findOrFail($paymentId);

        $order = null;
        if ($orderId) {
            $order = Order::where('merchant_id', $merchantId)->findOrFail($orderId);
        } elseif ($payment->order_id) {
            $order = Order::where('merchant_id', $merchantId)->find($payment->order_id);
        }

        return [$payment, $order];
    }

    protected function ensureRefundIsAllowed(Payment $payment, float $refundAmount, ?Refund $existingRefund = null): void
    {
        if (! in_array($payment->status, [PaymentStatus::Completed, PaymentStatus::Refunded], true)) {
            throw ValidationException::withMessages([
                'payment_id' => ['Refunds may only be created for completed or previously refunded payments.'],
            ]);
        }

        $refundedAmount = (float) $payment->refunds()
            ->when($existingRefund, fn ($query) => $query->whereKeyNot($existingRefund->id))
            ->where('status', RefundStatus::Processed)
            ->sum('amount');

        if ($refundAmount <= 0 || ($refundedAmount + $refundAmount) > (float) $payment->amount) {
            throw ValidationException::withMessages([
                'amount' => ['The refund amount exceeds the remaining refundable balance.'],
            ]);
        }
    }

    protected function syncRefundedBalances(Payment $payment, ?Order $order): void
    {
        $refundedAmount = (float) $payment->refunds()
            ->where('status', RefundStatus::Processed)
            ->sum('amount');

        if ($refundedAmount >= (float) $payment->amount) {
            $payment->update(['status' => PaymentStatus::Refunded]);

            if ($order) {
                $order->update(['payment_status' => OrderPaymentStatus::Refunded]);
            }

            return;
        }

        if ($refundedAmount > 0) {
            $payment->update(['status' => PaymentStatus::PartiallyRefunded]);

            if ($order) {
                $order->update(['payment_status' => OrderPaymentStatus::PartiallyRefunded]);
            }

            return;
        }

        $payment->update(['status' => PaymentStatus::Completed]);

        if ($order) {
            $order->update(['payment_status' => OrderPaymentStatus::Paid]);
        }
    }
}
